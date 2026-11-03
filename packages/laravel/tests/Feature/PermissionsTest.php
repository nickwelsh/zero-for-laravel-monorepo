<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionManager;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Tests\Fixtures\PermissionAdminPolicy;
use ZeroForLaravel\Tests\Fixtures\PermissionList;
use ZeroForLaravel\Tests\Fixtures\PermissionUser;
use ZeroForLaravel\Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    app()->register(PermissionServiceProvider::class);
    PermissionSchema::create(DB::connection());
    Schema::create('permission_test_users', function (Blueprint $table): void {
        $table->id();
        $table->string('department_id');
        $table->boolean('is_admin')->default(false);
    });
    Schema::create('permission_test_lists', function (Blueprint $table): void {
        $table->id();
        $table->string('owner_id');
        $table->string('department_id');
        $table->string('name');
    });
    config(['permissions.subject.class' => PermissionUser::class,
        'permissions.subject.attributes' => [
            'department_id' => ['column' => 'department_id', 'type' => 'string'],
            'is_admin' => ['column' => 'is_admin', 'type' => 'boolean'],
        ],
        'permissions.resources' => [PermissionList::class => [
            'type' => 'todo-list', 'domain' => ['column' => 'id', 'prefix' => 'list:'],
            'attributes' => [
                'owner_id' => ['column' => 'owner_id', 'type' => 'id'],
                'department_id' => ['column' => 'department_id', 'type' => 'string'],
            ],
        ]],
    ]);
    Route::get('/permission-lists/{list}', function (PermissionList $list): PermissionList {
        Gate::authorize('view', $list);

        return $list;
    })->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
    Route::patch('/permission-lists/{list}', function (PermissionList $list) {
        Gate::authorize('update', $list);

        return response()->noContent();
    })->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
    Route::delete('/permission-lists/{list}', function (PermissionList $list) {
        Gate::authorize('delete', $list);

        return response()->noContent();
    })->middleware(\Illuminate\Routing\Middleware\SubstituteBindings::class);
});

test('ordinary discovered policies protect REST without resolving Zero or generated registrations', function (): void {
    /** @var TestCase $this */
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $bob = PermissionUser::create(['id' => 84, 'department_id' => 'support']);
    PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private plans']);
    Casbin::addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'any', 'allow');
    Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:7');

    $this->actingAs($alice)->getJson('/permission-lists/7')->assertOk()->assertJsonPath('name', 'Private plans');
    $this->actingAs($bob)->getJson('/permission-lists/7')->assertForbidden();
    $this->actingAs($alice)->patchJson('/permission-lists/7')->assertNotFound();
    config(['permissions.denied_status' => 404]);
    $this->actingAs($bob)->getJson('/permission-lists/7')->assertNotFound();
    $this->actingAs($bob)->deleteJson('/permission-lists/7')->assertForbidden();
    $this->getJson('/permission-lists/999')->assertNotFound();
    expect(app()->resolved(ModelRegistry::class))->toBeFalse();
});

test('scoped roles never authorize another domain and revocation leaves unrelated assignments intact', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $first = PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'First']);
    $second = PermissionList::create(['id' => 8, 'owner_id' => '84', 'department_id' => 'support', 'name' => 'Second']);
    foreach (['list:7', 'list:8'] as $domain) {
        Casbin::addPolicy('role:reader', $domain, 'todo-list', 'view', 'any', 'allow');
    }

    Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:7');
    expect(Gate::forUser($alice)->allows('view', $first))->toBeTrue();
    expect(Gate::forUser($alice)->allows('view', $second))->toBeFalse();
    Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:8');
    Casbin::removeGroupingPolicy('user:42', 'role:reader', 'list:7');
    expect(Gate::forUser($alice)->allows('view', $first))->toBeFalse();
    expect(Gate::forUser($alice)->allows('view', $second))->toBeTrue();
});

test('managed rules are idempotent and revoked rules cannot survive an enforcer cache or rollback', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $list = PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private plans']);
    $manager = resolve(PermissionManager::class);
    $manager->addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'any', 'allow');

    expect($manager->addGroupingPolicy('user:42', 'role:reader', 'list:7'))->toBeTrue();
    expect($manager->addGroupingPolicy('user:42', 'role:reader', 'list:7'))->toBeFalse();
    expect(Gate::forUser($alice)->allows('view', $list))->toBeTrue();

    $revision = DB::table('permission_models')->value('revision');
    try {
        DB::transaction(function () use ($manager, $alice, $list): void {
            $manager->removeGroupingPolicy('user:42', 'role:reader', 'list:7');
            expect(Gate::forUser($alice)->allows('view', $list))->toBeFalse();
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Rollback');
    }

    expect(DB::table('permission_models')->value('revision'))->toBe($revision);
    expect(DB::table('permission_events')->count())->toBe(2);
    expect(Gate::forUser($alice)->allows('view', $list))->toBeTrue();

    $manager->removeGroupingPolicy('user:42', 'role:reader', 'list:7');
    expect(Gate::forUser($alice)->allows('view', $list))->toBeFalse();
});

test('attribute decisions refresh persisted facts and normal Laravel admin overrides bypass denials', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'support', 'is_admin' => true]);
    $list = PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private plans']);
    Casbin::addPolicy('role:auditor', 'list:7', 'todo-list', 'view', 'department', 'allow');
    Casbin::addGroupingPolicy('user:42', 'role:auditor', 'list:7');
    expect(Gate::forUser($alice)->allows('view', $list))->toBeTrue();
    DB::table('permission_test_users')->where('id', 42)->update(['department_id' => 'support']);
    expect(Gate::forUser($alice)->allows('view', $list))->toBeFalse();
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    Casbin::addPolicy('role:blocked', 'list:7', 'todo-list', 'view', 'any', 'deny');
    Casbin::addGroupingPolicy('user:99', 'role:blocked', 'list:7');
    expect(Gate::forUser($admin)->allows('view', $list))->toBeTrue();
    $admin->update(['is_admin' => false]);
    expect(Gate::forUser($admin)->allows('view', $list))->toBeFalse();
});
