<?php

use App\Http\Controllers\ShareListController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TodoListController;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Permissions\ListPermissions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Mutation\OperationException;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\ZeroContext;

function runTodoMutation(User $user, string $controller, string $method, array $input = [], array $route = []): array
{
    app(Kernel::class)->bootstrap();
    Auth::login($user);
    $models = app(ModelRegistry::class);
    $program = app(ControllerMutationCompiler::class)->compile($controller, $method);
    $source = (new ControllerMutationGenerator)->php([$program]);
    $factory = eval(substr($source, 5));
    $registry = $factory($models, app(ControllerRouteDispatcher::class));
    if (! $registry instanceof ControllerMutationRegistry) {
        throw new LogicException('Invalid generated mutation registry.');
    }
    $args = ['version' => $program->fingerprint, 'input' => $input, 'route' => $route,
        'prepared' => ['timestamp' => 1700000000123, 'ids' => array_map(fn () => (string) Str::uuid(), $program->sites)]];

    return $models->connection()->transaction(fn () => $registry->execute($program->name(), $args, new ZeroContext(['user_id' => $user->id])));
}

test('creating a list returns its identity and grants only its authenticated owner access', function () {
    $owner = User::factory()->create();
    $result = runTodoMutation($owner, TodoListController::class, 'store', ['name' => 'Home']);
    $list = TodoList::findOrFail($result['data']['id']);
    expect($list->user_id)->toBe($owner->id);
    expect(Gate::forUser($owner)->allows('view', $list))->toBeTrue();
    expect(Gate::forUser($owner)->allows('share', $list))->toBeTrue();
    $this->assertDatabaseCount('list_memberships', 0);
});

test('only the owner may delete a list and rejection preserves its rows', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = createTodoList($owner);
    ListPermissions::grantReader($list, $member->id);
    expect(fn () => runTodoMutation($member, TodoListController::class, 'destroy', route: ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
    $this->assertDatabaseHas('todo_lists', ['id' => $list->id]);
});

test('a shared member can add edit and delete todos through registered controller actions', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = createTodoList($owner, 'Shared');
    ListPermissions::grantReader($list, $member->id);
    $result = runTodoMutation($member, TodoController::class, 'store', ['list_id' => $list->id, 'title' => 'Milk']);
    $id = $result['data']['id'];
    runTodoMutation($member, TodoController::class, 'update', ['title' => 'Oat milk', 'completed' => true], ['todo' => $id]);
    $this->assertDatabaseHas('todos', ['id' => $id, 'title' => 'Oat milk', 'completed' => true]);
    runTodoMutation($member, TodoController::class, 'destroy', route: ['todo' => $id]);
    $this->assertDatabaseMissing('todos', ['id' => $id]);
});

test('outsiders cannot add todos to a private list', function () {
    $owner = User::factory()->create();
    $outsider = User::factory()->create();
    $list = createTodoList($owner, 'Private');
    expect(fn () => runTodoMutation($outsider, TodoController::class, 'store', ['list_id' => $list->id, 'title' => 'Attack']))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('todos', 0);
});

test('members cannot move a todo to a private list', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shared = createTodoList($owner, 'Shared');
    $private = createTodoList($owner, 'Private');
    ListPermissions::grantReader($shared, $member->id);
    $todo = Todo::create(['list_id' => $shared->id, 'title' => 'Milk']);
    expect(fn () => runTodoMutation($member, TodoController::class, 'update', ['list_id' => $private->id], ['todo' => $todo->id]))
        ->toThrow(OperationException::class, 'unavailable');
    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'list_id' => $shared->id]);
});

test('controller FormRequests reject empty titles without a write', function () {
    $owner = User::factory()->create();
    $list = createTodoList($owner);
    expect(fn () => runTodoMutation($owner, TodoController::class, 'store', ['list_id' => $list->id, 'title' => '']))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('todos', 0);
});

test('authoritative sharing is idempotent and conceals private resources and accounts', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = createTodoList($owner);
    for ($i = 0; $i < 2; $i++) {
        runTodoMutation($owner, ShareListController::class, '__invoke', ['email' => $member->email], ['list' => $list->id]);
    }
    expect(DB::table('permission_rules')->where('ptype', 'g')->where('v0', 'user:'.$member->id)->count())->toBe(1);
    expect(Gate::forUser($member)->allows('view', $list))->toBeTrue();
    expect(Gate::forUser($member)->allows('update', $list))->toBeFalse();
    expect(fn () => runTodoMutation($member, ShareListController::class, '__invoke', ['email' => $owner->email], ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
    expect(fn () => runTodoMutation($owner, ShareListController::class, '__invoke', ['email' => 'missing@example.com'], ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
});

test('deleting a list cascades its todos and removes managed grants', function () {
    $owner = User::factory()->create();
    $list = createTodoList($owner);
    Todo::create(['list_id' => $list->id, 'title' => 'Milk']);
    runTodoMutation($owner, TodoListController::class, 'destroy', route: ['list' => $list->id]);
    $this->assertDatabaseCount('todos', 0);
    $this->assertDatabaseCount('list_memberships', 0);
    $this->assertDatabaseCount('permission_rules', 0);
});

test('ordinary discovered policies compile for both explicit resource models', function () {
    $compiler = new PolicyCompiler;
    foreach ([TodoList::class, Todo::class] as $class) {
        expect($compiler->compile($class)->action)->toBe('view');
        expect($compiler->compile($class)->deniedStatus)->toBe(404);
    }

    expect(array_values(array_map(fn ($model) => $model->class, app(ModelRegistry::class)->all())))
        ->toBe([TodoList::class, Todo::class]);
});

test('unsharing revokes list and todo access without affecting owner or other lists', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = createTodoList($owner);
    $other = createTodoList($owner, 'Other');
    $todo = Todo::create(['list_id' => $list->id, 'title' => 'Milk']);
    foreach ([$list, $other] as $shared) {
        ListPermissions::grantReader($shared, $member->id);
    }

    expect(Gate::forUser($member)->allows('view', $todo))->toBeTrue();
    for ($i = 0; $i < 2; $i++) {
        runTodoMutation($owner, ShareListController::class, 'destroy', ['email' => $member->email], ['list' => $list->id]);
    }

    expect(Gate::forUser($member)->allows('view', $list))->toBeFalse();
    expect(Gate::forUser($member)->allows('view', $todo))->toBeFalse();
    expect(Gate::forUser($member)->allows('view', $other))->toBeTrue();
    expect(Gate::forUser($owner)->allows('share', $list))->toBeTrue();
    expect(fn () => runTodoMutation($member, TodoController::class, 'update', ['completed' => true], ['todo' => $todo->id]))
        ->toThrow(OperationException::class, 'unavailable');
    expect(fn () => runTodoMutation($member, ShareListController::class, 'destroy', ['email' => $owner->email], ['list' => $other->id]))
        ->toThrow(OperationException::class, 'unavailable');
});

test('owner columns and legacy memberships cannot substitute for Casbin grants', function () {
    $owner = User::factory()->create();
    $list = TodoList::create(['name' => 'Not granted', 'user_id' => $owner->id]);
    DB::table('list_memberships')->insert(['id' => (string) Str::uuid(), 'list_id' => $list->id, 'user_id' => $owner->id]);
    expect(Gate::forUser($owner)->allows('view', $list))->toBeFalse();
    expect(Gate::forUser($owner)->allows('update', $list))->toBeFalse();
});

test('the backfill translates owners and existing memberships to managed grants', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $outsider = User::factory()->create();
    $list = TodoList::create(['name' => 'Existing', 'user_id' => $owner->id]);
    DB::table('list_memberships')->insert(['id' => (string) Str::uuid(), 'list_id' => $list->id, 'user_id' => $member->id]);
    $migration = require database_path('migrations/2026_11_03_000001_backfill_list_permissions.php');
    $migration->up();
    $count = DB::table('permission_rules')->count();
    $migration->up();
    expect(DB::table('permission_rules')->count())->toBe($count);
    expect(Gate::forUser($owner)->allows('share', $list))->toBeTrue();
    expect(Gate::forUser($member)->allows('view', $list))->toBeTrue();
    expect(Gate::forUser($member)->allows('delete', $list))->toBeFalse();
    expect(Gate::forUser($outsider)->allows('view', $list))->toBeFalse();
    ListPermissions::revokeReader($list, $member->id);
    expect(Gate::forUser($member)->allows('view', $list))->toBeFalse();
});

test('a failed grant rolls back list creation and permission events', function () {
    $owner = User::factory()->create();
    $events = DB::table('permission_events')->count();
    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'insert') && str_contains($query->sql, 'permission_rules')) {
            throw new RuntimeException('Grant failed');
        }
    });
    expect(fn () => runTodoMutation($owner, TodoListController::class, 'store', ['name' => 'Failed']))
        ->toThrow(RuntimeException::class, 'Grant failed');
    $this->assertDatabaseCount('todo_lists', 0);
    $this->assertDatabaseCount('permission_rules', 0);
    expect(DB::table('permission_events')->count())->toBe($events);
});

test('creation cannot spoof the list owner or persist unvalidated fields', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $result = runTodoMutation($owner, TodoListController::class, 'store', [
        'name' => 'Mine', 'user_id' => $other->id, 'id' => (string) Str::uuid(),
    ]);
    $list = TodoList::findOrFail($result['data']['id']);
    expect($list->user_id)->toBe($owner->id);
    expect(Gate::forUser($owner)->allows('view', $list))->toBeTrue();
    expect(Gate::forUser($other)->allows('view', $list))->toBeFalse();
});

test('rolling back unshare restores access and leaves its permission events unchanged', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = createTodoList($owner);
    ListPermissions::grantReader($list, $member->id);
    $events = DB::table('permission_events')->count();
    expect(fn () => DB::transaction(function () use ($owner, $member, $list): void {
        runTodoMutation($owner, ShareListController::class, 'destroy', ['email' => $member->email], ['list' => $list->id]);
        expect(Gate::forUser($member)->allows('view', $list))->toBeFalse();
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    expect(Gate::forUser($member)->allows('view', $list))->toBeTrue();
    expect(DB::table('permission_events')->count())->toBe($events);
});
