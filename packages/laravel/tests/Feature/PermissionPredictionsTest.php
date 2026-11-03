<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;
use stdClass;
use Symfony\Component\Process\Process;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\PredictionController;
use ZeroForLaravel\Permissions\Predictions;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Tests\Fixtures\PermissionAdminPolicy;
use ZeroForLaravel\Tests\Fixtures\PermissionList;
use ZeroForLaravel\Tests\Fixtures\PermissionUser;
use ZeroForLaravel\Tests\PostgresTestCase;

uses(PostgresTestCase::class);

beforeEach(function (): void {
    app()->register(PermissionServiceProvider::class);
    PermissionSchema::create(DB::connection());
    Schema::create('permission_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('department_id');
        $table->boolean('is_admin')->default(false);
        $table->string('secret_token')->default('subject-secret');
    });
    Schema::create('permission_test_lists', function (Blueprint $table): void {
        $table->id();
        $table->string('owner_id');
        $table->string('department_id');
        $table->string('name');
        $table->string('secret_notes')->default('resource-secret');
    });
    config(['permissions.subject.class' => PermissionUser::class,
        'permissions.subject.attributes' => [
            'department_id' => ['column' => 'department_id', 'type' => 'string'],
            'is_admin' => ['column' => 'is_admin', 'type' => 'boolean'],
            'private_token' => ['column' => 'secret_token', 'type' => 'string'],
        ],
        'permissions.resources' => [PermissionList::class => [
            'type' => 'todo-list', 'domain' => ['column' => 'id', 'prefix' => 'list:'],
            'attributes' => [
                'owner_id' => ['column' => 'owner_id', 'type' => 'id'],
                'department_id' => ['column' => 'department_id', 'type' => 'string'],
                'private_notes' => ['column' => 'secret_notes', 'type' => 'string'],
            ],
        ]],
        'permissions.predictions' => [
            'abilities' => [PermissionList::class => ['view']],
            'subject_attributes' => ['department_id', 'is_admin'],
            'resource_attributes' => [PermissionList::class => ['id', 'owner_id', 'department_id']],
        ],
    ]);
    (new ProjectionManager)->rebuild();
});

afterEach(function (): void {
    if (! getenv('ZERO_TEST_PG_DATABASE')) {
        return;
    }

    foreach (DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' AND tablename LIKE 'permission_%'") as $row) {
        if ($row instanceof stdClass && is_string($row->tablename)) {
            Schema::dropIfExists($row->tablename);
        }
    }

    foreach (DB::select("SELECT proname, pg_get_function_identity_arguments(oid) AS args FROM pg_proc WHERE pronamespace = 'public'::regnamespace AND proname LIKE 'zero_permissions_%'") as $row) {
        if ($row instanceof stdClass && is_string($row->proname) && is_string($row->args)) {
            DB::connection()->getPdo()->exec('DROP FUNCTION IF EXISTS "'.$row->proname.'"('.$row->args.') CASCADE');
        }
    }
});

test('the registered prediction endpoint derives its identity from authentication and rejects subject arguments', function (): void {
    /** @var PostgresTestCase $this */
    Route::get('/current-permissions', PredictionController::class);
    $this->getJson('/current-permissions')->assertUnauthorized();
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    PermissionUser::create(['id' => 84, 'department_id' => 'support']);
    $this->actingAs($alice)->getJson('/current-permissions')->assertOk()
        ->assertJsonPath('subject.id', '42')->assertJsonMissing(['secret_token' => 'subject-secret'])
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/current-permissions?user_id=84')->assertUnprocessable();
});

test('prediction feeds expose only current subject facts and applicable effective grants without graph or provenance', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $bob = PermissionUser::create(['id' => 84, 'department_id' => 'support', 'secret_token' => 'bob-secret']);
    Casbin::addPolicy('role:alice-reader', 'list:7', 'todo-list', 'view', 'owner', 'allow');
    Casbin::addPolicy('role:bob-reader', 'list:8', 'todo-list', 'view', 'any', 'allow');
    Casbin::addPolicy('role:alice-reader', 'list:7', 'todo-list', 'update', 'any', 'allow');
    Casbin::addPolicy('role:alice-reader', 'list:7', 'other-type', 'view', 'any', 'allow');
    Casbin::addGroupingPolicy('user:42', 'role:alice-reader', 'list:7');
    Casbin::addGroupingPolicy('user:84', 'role:bob-reader', 'list:8');
    $abilities = [['resource' => PermissionList::class, 'ability' => 'view'], ['resource' => PermissionList::class, 'ability' => 'update']];
    $a = Predictions::forUser($alice, $abilities);
    $b = Predictions::forUser($bob, $abilities);
    expect($a['version'])->toBe(1);
    expect($a['subject']['id'])->toBe('42');
    expect($a['subject']['key'])->toBe('user:42');
    expect((array) $a['subject']['attributes'])->toBe(['department_id' => 'engineering', 'is_admin' => false]);

    $view = data_get($a, 'resources.'.PermissionList::class.'.view');
    expect((array) data_get($a, 'resources.'.PermissionList::class))->toHaveKeys(['view']);
    expect(data_get($a, 'resources.'.PermissionList::class.'.update'))->toBeNull();
    expect(data_get($view, 'grants'))->toHaveCount(1);
    expect((array) data_get($view, 'grants.0.values'))->not->toHaveKey('v0');

    $encoded = json_encode($a, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('list:7');
    expect($encoded)->not->toContain('list:8', 'user:84', 'role:', 'rule_id', 'subject_key', 'private_token', 'secret_token', 'subject-secret', 'bob-secret', 'private_notes', 'resource-secret', 'other-type');
    expect(json_encode($b, JSON_THROW_ON_ERROR))->toContain('list:8');
    expect(json_encode($b, JSON_THROW_ON_ERROR))->not->toContain('list:7', 'user:42');
});

test('predictions are absent by default and omit abilities whose required attributes are not exposable', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $abilities = [['resource' => PermissionList::class, 'ability' => 'view']];
    config(['permissions.predictions' => []]);
    $feed = Predictions::forUser($alice, $abilities);
    expect((array) $feed['resources'])->toBe([]);
    expect((array) $feed['subject']['attributes'])->toBe([]);
    config(['permissions.predictions.abilities' => [PermissionList::class => ['view']],
        'permissions.predictions.resource_attributes' => [PermissionList::class => ['id', 'owner_id', 'department_id']]]);
    expect((array) Predictions::forUser($alice, $abilities)['resources'])->toBe([]);
    config(['permissions.predictions.subject_attributes' => ['department_id'],
        'permissions.predictions.resource_attributes' => [PermissionList::class => ['id', 'owner_id']]]);
    expect((array) Predictions::forUser($alice, $abilities)['resources'])->toBe([]);
    config(['permissions.predictions.resource_attributes' => [PermissionList::class => ['owner_id', 'department_id']]]);
    expect((array) Predictions::forUser($alice, $abilities)['resources'])->toBe([]);
});

test('prediction subject facts are refreshed from the authenticated database identity', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $alice->setAttribute('department_id', 'forged');
    $alice->is_admin = true;

    $feed = Predictions::forUser($alice, [['resource' => PermissionList::class, 'ability' => 'view']]);
    expect((array) $feed['subject']['attributes'])->toBe(['department_id' => 'engineering', 'is_admin' => false]);
    expect(fn (): array => Predictions::forUser(new GenericUser(['id' => 84]), []))
        ->toThrow(LogicException::class, 'authenticated Eloquent subject');
});

test('admin prediction plans require explicit boolean exposure and preserve the ordinary policy', function (): void {
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'support', 'is_admin' => true]);
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    $abilities = [['resource' => PermissionList::class, 'ability' => 'view']];
    config(['permissions.predictions.subject_attributes' => ['department_id']]);
    expect((array) Predictions::forUser($admin, $abilities)['resources'])->toBe([]);
    config(['permissions.predictions.subject_attributes' => ['department_id', 'is_admin']]);
    $feed = Predictions::forUser($admin, $abilities);
    $view = data_get($feed, 'resources.'.PermissionList::class.'.view');
    expect(data_get($view, 'admin'))->toBe('is_admin');
    expect(data_get($view, 'subjectAttributes.is_admin'))->toBe('boolean');
    expect(data_get($view, 'grants'))->toBe([]);
    expect(data_get($view, 'denyOverride'))->toBeTrue();
});

test('prediction feeds immediately reflect managed revocation and rollback without inventing grants', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    Casbin::addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'any', 'allow');
    Casbin::addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'any', 'deny');
    Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:7');
    $abilities = [['resource' => PermissionList::class, 'ability' => 'view']];
    $before = Predictions::forUser($alice, $abilities);
    expect(data_get($before, 'resources.'.PermissionList::class.'.view.grants.*.effect'))->toContain('allow', 'deny');
    DB::beginTransaction();
    Casbin::removeGroupingPolicy('user:42', 'role:reader', 'list:7');
    $revoked = Predictions::forUser($alice, $abilities);
    expect(data_get($revoked, 'resources.'.PermissionList::class.'.view.grants'))->toBe([]);
    DB::rollBack();
    expect(Predictions::forUser($alice, $abilities))->toEqual($before);
});

test('role-free grants are already expanded per subject without publishing graph provenance', function (): void {
    config(['permissions.models.default' => dirname(__DIR__, 2).'/config/permissions/abac.conf']);
    (new ProjectionManager)->rebuild();
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    Casbin::addPolicy('list:7', 'todo-list', 'view', 'department', 'allow');
    $feed = Predictions::forUser($alice, [['resource' => PermissionList::class, 'ability' => 'view']]);
    $view = data_get($feed, 'resources.'.PermissionList::class.'.view');
    expect(data_get($view, 'grants'))->toHaveCount(1);
    expect((array) data_get($view, 'grants.0.values'))->toHaveKey('s_department_id', 'engineering');
    expect(json_encode($feed, JSON_THROW_ON_ERROR))->not->toContain('role:', 'rule_id');
});

test('direct global ACLs need no attribute exposure and serialize empty correlation maps as objects', function (): void {
    config(['permissions.models.default' => dirname(__DIR__, 2).'/config/permissions/global-acl.conf',
        'permissions.predictions.subject_attributes' => [],
        'permissions.predictions.resource_attributes' => []]);
    (new ProjectionManager)->rebuild();
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    Casbin::addPolicy('user:42', 'todo-list', 'view', 'any', 'allow');
    Casbin::addPolicy('user:84', 'todo-list', 'view', 'any', 'allow');
    $feed = Predictions::forUser($alice, [['resource' => PermissionList::class, 'ability' => 'view']]);
    $view = data_get($feed, 'resources.'.PermissionList::class.'.view');
    expect(data_get($view, 'grants'))->toHaveCount(1);
    expect(data_get($view, 'branches.0.correlations'))->toEqual(new stdClass);
    expect(json_encode($feed, JSON_THROW_ON_ERROR))->toContain('"correlations":{}');
    expect(json_encode($feed, JSON_THROW_ON_ERROR))->not->toContain('user:84', 'department_id', 'owner_id');
});

test('serialized current-user feeds evaluated by the real browser module agree with asymmetric Gates', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $bob = PermissionUser::create(['id' => 84, 'department_id' => 'support']);
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'support', 'is_admin' => true]);
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    $lists = [
        PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Owned']),
        PermissionList::create(['id' => 8, 'owner_id' => '84', 'department_id' => 'support', 'name' => 'Department']),
        PermissionList::create(['id' => 9, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Denied']),
    ];
    Casbin::addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'owner', 'allow');
    Casbin::addPolicy('role:reader', 'list:8', 'todo-list', 'view', 'department', 'allow');
    Casbin::addPolicy('role:reader', 'list:9', 'todo-list', 'view', 'any', 'allow');
    Casbin::addPolicy('role:reader', 'list:9', 'todo-list', 'view', 'any', 'deny');
    foreach ([7, 8, 9] as $id) {
        Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:'.$id);
    }

    Casbin::addGroupingPolicy('user:84', 'role:reader', 'list:8');
    $cases = [];
    $expected = [];
    foreach ([$alice, $bob, $admin] as $user) {
        $feed = Predictions::forUser($user, [['resource' => PermissionList::class, 'ability' => 'view']]);
        foreach ($lists as $list) {
            $cases[] = ['feed' => $feed, 'resource' => PermissionList::class, 'ability' => 'view',
                'row' => $list->only(['id', 'owner_id', 'department_id']), 'subjectId' => Binding::id($user->getAuthIdentifier())];
            $expected[] = Gate::forUser($user)->allows('view', $list);
        }
    }

    expect($expected)->toBe([true, false, false, false, true, false, true, true, true]);
    // Alice's valid persisted feed must remain unavailable after switching to Bob.
    $cases[] = [...$cases[0], 'subjectId' => Binding::id($bob->getAuthIdentifier())];
    $expected[] = null; // JSON.stringify serializes an undefined array entry as null.
    $process = new Process(['node', '--input-type=module', '--eval', <<<'JS_WRAP'
    import {readFileSync} from 'node:fs';
    import {predictPermission} from './packages/client/src/permissions.ts';
    const cases = JSON.parse(readFileSync(0, 'utf8'));
    process.stdout.write(JSON.stringify(cases.map(({feed, resource, ability, row, subjectId}) => predictPermission(feed, resource, ability, row, subjectId))));
    JS_WRAP,
    ], dirname(__DIR__, 4));
    $process->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
    $process->mustRun();

    expect(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR))->toBe($expected);
});
