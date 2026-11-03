<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use LogicException;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Tests\Fixtures\PermissionAdminPolicy;
use ZeroForLaravel\Tests\Fixtures\PermissionList;
use ZeroForLaravel\Tests\Fixtures\PermissionSoftDeletedList;
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
    (new ProjectionManager)->rebuild();
});

afterEach(function (): void {
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

test('visible collections agree with Gates before counts and windows and preserve caller filters for admins', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'support', 'is_admin' => true]);
    $rows = [
        [7, '42', 'engineering', 'Owned with grant'],
        [8, '42', 'engineering', 'Owned without grant'],
        [9, '84', 'engineering', 'Department'],
        [10, '84', 'support', 'Wrong department'],
        [11, '84', 'support', 'Denied'],
    ];
    foreach ($rows as [$id, $owner, $department, $name]) {
        PermissionList::create(['id' => $id, 'owner_id' => $owner, 'department_id' => $department, 'name' => $name]);
    }

    foreach ([7 => 'owner', 9 => 'department', 10 => 'department', 11 => 'any'] as $id => $condition) {
        Casbin::addPolicy('role:reader', 'list:'.$id, 'todo-list', 'view', $condition, 'allow');
        Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:'.$id);
    }

    Casbin::addPolicy('role:reader', 'list:11', 'todo-list', 'view', 'any', 'deny');
    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    expect(PermissionList::query()->visibleTo($alice)->orderBy('id')->pluck('id')->all())->toBe([7, 9]);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(2);
    expect(PermissionList::query()->visibleTo($alice)->orderBy('id')->paginate(1, page: 2)->pluck('id')->all())->toBe([9]);
    foreach (PermissionList::all() as $list) {
        expect(PermissionList::query()->visibleTo($alice)->whereKey($list->getKey())->exists())->toBe(Gate::forUser($alice)->allows('view', $list));
    }

    expect(PermissionList::query()->where('id', '>', 8)->visibleTo($admin)->pluck('id')->all())->toBe([9, 10, 11]);
    Casbin::addGroupingPolicy('user:99', 'role:reader', 'list:11');
    expect(PermissionList::query()->visibleTo($admin)->whereKey(11)->exists())->toBeTrue();
    DB::table('permission_test_users')->where('id', 99)->update(['is_admin' => false]);
    expect(PermissionList::query()->visibleTo($admin)->count())->toBe(0);
});

test('direct SQL subject and resource changes update visibility atomically and rollback restores access', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    PermissionList::create(['id' => 7, 'owner_id' => '84', 'department_id' => 'engineering', 'name' => 'Department']);
    Casbin::addPolicy('role:reader', 'list:7', 'todo-list', 'view', 'department', 'allow');
    Casbin::addGroupingPolicy('user:42', 'role:reader', 'list:7');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    DB::table('permission_test_users')->where('id', 42)->update(['department_id' => 'support']);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
    DB::table('permission_test_lists')->where('id', 7)->update(['department_id' => 'support']);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    $events = DB::table('permission_events')->count();
    try {
        DB::transaction(function () use ($alice): void {
            Casbin::removeGroupingPolicy('user:42', 'role:reader', 'list:7');
            DB::table('permission_test_users')->where('id', 42)->update(['is_admin' => true]);
            expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
            throw new RuntimeException('Rollback');
        });
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())->toBe('Rollback');
    }

    expect(DB::table('permission_events')->count())->toBe($events);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
});

test('alternate role paths survive removal without leaking across domains and rebuild retains the same rows', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    foreach ([7, 8] as $id) {
        PermissionList::create(['id' => $id, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'List '.$id]);
        Casbin::addPolicy('role:reader', 'list:'.$id, 'todo-list', 'view', 'owner', 'allow');
    }

    Casbin::addGroupingPolicy('user:42', 'role:first', 'list:7');
    Casbin::addGroupingPolicy('user:42', 'role:second', 'list:7');
    Casbin::addGroupingPolicy('role:first', 'role:reader', 'list:7');
    Casbin::addGroupingPolicy('role:second', 'role:reader', 'list:7');
    expect(PermissionList::query()->visibleTo($alice)->pluck('id')->all())->toBe([7]);
    Casbin::removeGroupingPolicy('role:first', 'role:reader', 'list:7');
    expect(PermissionList::query()->visibleTo($alice)->pluck('id')->all())->toBe([7]);
    (new ProjectionManager)->rebuild();
    expect(PermissionList::query()->visibleTo($alice)->pluck('id')->all())->toBe([7]);
    DB::table('permission_test_lists')->where('id', 7)->update(['owner_id' => '84']);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
    DB::table('permission_test_lists')->where('id', 7)->update(['owner_id' => '42']);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    Casbin::removeGroupingPolicy('role:second', 'role:reader', 'list:7');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
});

test('a policy admin falls back to normal grants when its live override is removed', function (): void {
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'engineering', 'is_admin' => true]);
    foreach ([7, 8] as $id) {
        PermissionList::create(['id' => $id, 'owner_id' => '99', 'department_id' => 'engineering', 'name' => 'List '.$id]);
    }

    Gate::policy(PermissionList::class, PermissionAdminPolicy::class);
    Casbin::addPolicy('user:99', 'list:7', 'todo-list', 'view', 'any', 'allow');
    Casbin::addPolicy('user:99', 'list:8', 'todo-list', 'view', 'any', 'deny');
    expect(PermissionList::query()->visibleTo($admin)->orderBy('id')->pluck('id')->all())->toBe([7, 8]);
    DB::table('permission_test_users')->where('id', 99)->update(['is_admin' => false]);
    expect(PermissionList::query()->visibleTo($admin)->pluck('id')->all())->toBe([7]);
    expect(PermissionList::query()->visibleTo($admin)->whereKey(7)->exists())->toBe(Gate::forUser($admin->fresh())->allows('view', PermissionList::findOrFail(7)));
    DB::table('permission_test_users')->where('id', 99)->delete();
    expect(PermissionList::query()->visibleTo($admin)->count())->toBe(0);
});

test('all published portable profiles filter actual collections with the same Gate decisions', function (string $profile): void {
    config(['permissions.models.default' => __DIR__.'/../../config/permissions/'.$profile.'.conf']);
    (new ProjectionManager)->rebuild();
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    $bob = PermissionUser::create(['id' => 84, 'department_id' => 'support']);
    foreach ([7, 8] as $id) {
        PermissionList::create(['id' => $id, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'List '.$id]);
    }

    match ($profile) {
        'global-acl' => Casbin::addPolicy('user:42', 'todo-list', 'view', 'any', 'allow'),
        'global-rbac' => Casbin::addPolicy('role:reader', 'todo-list', 'view', 'any', 'allow'),
        'domain-acl' => Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'any', 'allow'),
        'abac' => Casbin::addPolicy('list:7', 'todo-list', 'view', 'department', 'allow'),
        default => throw new LogicException('Unknown test profile'),
    };
    if ($profile === 'global-rbac') {
        Casbin::addGroupingPolicy('user:42', 'role:reader');
    }

    $expected = str_starts_with($profile, 'global-') ? [7, 8] : [7];
    expect(PermissionList::query()->visibleTo($alice)->orderBy('id')->pluck('id')->all())->toBe($expected);
    expect(PermissionList::query()->visibleTo($bob)->count())->toBe(0);
    foreach ([$alice, $bob] as $user) {
        foreach (PermissionList::all() as $list) {
            expect(PermissionList::query()->visibleTo($user)->whereKey($list->getKey())->exists())->toBe(Gate::forUser($user)->allows('view', $list));
        }
    }
})->with(['global-acl', 'global-rbac', 'domain-acl', 'abac']);

test('unknown conditions and raw rule writes cannot bypass managed consistency', function (): void {
    expect(fn () => Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'typo', 'allow'))->toThrow(LogicException::class, 'Unknown portable');
    expect(fn () => DB::table('permission_rules')->insert(['id' => 'forged', 'model' => 'default', 'ptype' => 'p']))->toThrow(\Illuminate\Database\QueryException::class, 'Use managed Casbin writes');
    DB::transaction(function (): void {
        Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'any', 'allow');
        try {
            DB::transaction(fn () => DB::table('permission_rules')->delete());
            throw new RuntimeException('Raw writes were accepted');
        } catch (\Illuminate\Database\QueryException $queryException) {
            expect($queryException->getMessage())->toContain('Use managed Casbin writes');
        }
    });
    expect(DB::table('permission_rules')->count())->toBe(1);
});

test('concurrent source writes cannot resurrect a revoked grant from an older snapshot', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private']);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'any', 'allow');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    $connection = DB::connection();
    $settings = $connection->getConfig();
    if (! is_array($settings) || ! is_string($settings['host']) || ! is_string($settings['database']) || ! is_string($settings['username']) || ! is_string($settings['password']) || (! is_int($settings['port']) && ! is_string($settings['port']))) {
        throw new LogicException('Invalid PostgreSQL test connection');
    }

    $writer = new Process([PHP_BINARY, '-r', <<<'PHP'
        $pdo = new PDO(getenv('PERMISSION_DSN'), getenv('PERMISSION_USER'), getenv('PERMISSION_PASSWORD'));
        $pdo->exec("SET application_name = 'permission_concurrent_writer'; SET lock_timeout = '10s'");
        echo "connected\n";
        $pdo->exec("UPDATE permission_test_users SET department_id = 'support' WHERE id = 42");
        echo "committed\n";
        PHP,
    ], null, [
        'PERMISSION_DSN' => 'pgsql:host='.$settings['host'].';port='.$settings['port'].';dbname='.$settings['database'],
        'PERMISSION_USER' => $settings['username'], 'PERMISSION_PASSWORD' => $settings['password'],
    ]);
    $connection->beginTransaction();
    try {
        Casbin::removePolicy('user:42', 'list:7', 'todo-list', 'view', 'any', 'allow');
        $writer->start();
        expect($writer->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'connected')))->toBeTrue();
        $blocked = false;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (DB::scalar("SELECT EXISTS (SELECT 1 FROM pg_stat_activity WHERE application_name = 'permission_concurrent_writer' AND wait_event = 'advisory')") === true) {
                $blocked = true;
                break;
            }

            \Illuminate\Support\Sleep::usleep(10000);
        }

        expect($blocked)->toBeTrue();
        $connection->commit();
    } finally {
        if ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
    }

    expect($writer->wait())->toBe(0);
    expect($writer->getOutput())->toContain('committed');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
    expect(Gate::forUser($alice)->allows('view', PermissionList::findOrFail(7)))->toBeFalse();
});

test('numeric-looking string identifiers and attributes never gain PHP loose-equality access', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => '001']);
    $list = PermissionList::create(['id' => 7, 'owner_id' => '00042', 'department_id' => '1', 'name' => 'Not the same identifiers']);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'owner', 'allow');
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'department', 'allow');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
    expect(Gate::forUser($alice)->allows('view', $list))->toBeFalse();
    DB::table('permission_test_lists')->where('id', 7)->update(['owner_id' => '42']);
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    expect(Gate::forUser($alice)->allows('view', $list))->toBeTrue();
});

test('soft deletion and restoration maintain visibility and cannot be bypassed with admin or withTrashed', function (): void {
    Schema::table('permission_test_lists', fn (Blueprint $table) => $table->softDeletes());
    config(['permissions.resources' => [PermissionSoftDeletedList::class => config('permissions.resources.'.PermissionList::class)]]);
    Gate::policy(PermissionSoftDeletedList::class, PermissionAdminPolicy::class);
    (new ProjectionManager)->rebuild();
    $admin = PermissionUser::create(['id' => 99, 'department_id' => 'support', 'is_admin' => true]);
    $list = PermissionSoftDeletedList::create(['id' => 7, 'owner_id' => '84', 'department_id' => 'engineering', 'name' => 'Recoverable']);
    expect(PermissionSoftDeletedList::query()->visibleTo($admin)->count())->toBe(1);
    $list->delete();
    expect(PermissionSoftDeletedList::withTrashed()->visibleTo($admin)->count())->toBe(0);
    $list->restore();
    expect(PermissionSoftDeletedList::query()->visibleTo($admin)->count())->toBe(1);
    $list->forceDelete();
    expect(PermissionSoftDeletedList::withTrashed()->visibleTo($admin)->count())->toBe(0);
});

test('binding changes fail closed until a transactional rebuild and foreign actor connections are rejected', function (): void {
    $alice = PermissionUser::create(['id' => 42, 'department_id' => 'engineering']);
    PermissionList::create(['id' => 7, 'owner_id' => '42', 'department_id' => 'engineering', 'name' => 'Private']);
    Casbin::addPolicy('user:42', 'list:7', 'todo-list', 'view', 'owner', 'allow');
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(1);
    config(['permissions.subject.prefix' => 'member:']);
    expect(fn () => PermissionList::query()->visibleTo($alice)->get())->toThrow(LogicException::class, 'stale');
    (new ProjectionManager)->rebuild();
    expect(PermissionList::query()->visibleTo($alice)->count())->toBe(0);
    config(['database.connections.foreign' => DB::connection()->getConfig()]);
    config(['database.connections.foreign.name' => 'foreign']);
    $alice->setConnection('foreign');
    expect(fn () => PermissionList::query()->visibleTo($alice)->get())->toThrow(LogicException::class, 'one PostgreSQL');
});
