<?php

use App\Http\Controllers\ShareListController;
use App\Http\Controllers\TodoController;
use App\Http\Controllers\TodoListController;
use App\Models\ListMembership;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZeroForLaravel\Domain\OperationException;
use ZeroForLaravel\Generation\Mutations\ControllerMutationCompiler;
use ZeroForLaravel\Generation\Mutations\ControllerMutationGenerator;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\ControllerMutationRegistry;
use ZeroForLaravel\Query\QueryCompiler;
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

test('creating a list returns its stable identity and adds its owner as a member', function () {
    $owner = User::factory()->create();
    $result = runTodoMutation($owner, TodoListController::class, 'store', ['name' => 'Home']);
    $list = TodoList::findOrFail($result['data']['id']);
    expect($list->user_id)->toBe($owner->id);
    $this->assertDatabaseHas('list_memberships', ['list_id' => $list->id, 'user_id' => $owner->id]);
});

test('only the owner may delete a list and rejection preserves its rows', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = TodoList::create(['name' => 'Home', 'user_id' => $owner->id]);
    $list->memberships()->create(['user_id' => $member->id]);
    expect(fn () => runTodoMutation($member, TodoListController::class, 'destroy', route: ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
    $this->assertDatabaseHas('todo_lists', ['id' => $list->id]);
});

test('a shared member can add edit and delete todos through registered controller actions', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = TodoList::create(['name' => 'Shared', 'user_id' => $owner->id]);
    $list->memberships()->create(['user_id' => $member->id]);
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
    $list = TodoList::create(['name' => 'Private', 'user_id' => $owner->id]);
    expect(fn () => runTodoMutation($outsider, TodoController::class, 'store', ['list_id' => $list->id, 'title' => 'Attack']))
        ->toThrow(AuthorizationException::class);
    $this->assertDatabaseCount('todos', 0);
});

test('members cannot move a todo to a private list', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $shared = TodoList::create(['name' => 'Shared', 'user_id' => $owner->id]);
    $private = TodoList::create(['name' => 'Private', 'user_id' => $owner->id]);
    $shared->memberships()->create(['user_id' => $member->id]);
    $todo = Todo::create(['list_id' => $shared->id, 'title' => 'Milk']);
    expect(fn () => runTodoMutation($member, TodoController::class, 'update', ['list_id' => $private->id], ['todo' => $todo->id]))
        ->toThrow(OperationException::class, 'unavailable');
    $this->assertDatabaseHas('todos', ['id' => $todo->id, 'list_id' => $shared->id]);
});

test('controller FormRequests reject empty titles without a write', function () {
    $owner = User::factory()->create();
    $list = TodoList::create(['name' => 'Home', 'user_id' => $owner->id]);
    expect(fn () => runTodoMutation($owner, TodoController::class, 'store', ['list_id' => $list->id, 'title' => '']))
        ->toThrow(ValidationException::class);
    $this->assertDatabaseCount('todos', 0);
});

test('authoritative sharing is idempotent and conceals private resources and accounts', function () {
    $owner = User::factory()->create();
    $member = User::factory()->create();
    $list = TodoList::create(['name' => 'Home', 'user_id' => $owner->id]);
    for ($i = 0; $i < 2; $i++) {
        runTodoMutation($owner, ShareListController::class, '__invoke', ['email' => $member->email], ['list' => $list->id]);
    }
    expect($list->memberships()->where('user_id', $member->id)->count())->toBe(1);
    expect(fn () => runTodoMutation($member, ShareListController::class, '__invoke', ['email' => $owner->email], ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
    expect(fn () => runTodoMutation($owner, ShareListController::class, '__invoke', ['email' => 'missing@example.com'], ['list' => $list->id]))
        ->toThrow(OperationException::class, 'unavailable');
});

test('deleting a list cascades its todos and memberships', function () {
    $owner = User::factory()->create();
    $list = TodoList::create(['name' => 'Home', 'user_id' => $owner->id]);
    Todo::create(['list_id' => $list->id, 'title' => 'Milk']);
    runTodoMutation($owner, TodoListController::class, 'destroy', route: ['list' => $list->id]);
    $this->assertDatabaseCount('todos', 0);
    $this->assertDatabaseCount('list_memberships', 0);
});

test('query declarations enforce membership at list and todo roots', function () {
    $owner = User::factory()->create();
    $compiler = new QueryCompiler(new ModelRegistry([TodoList::class, Todo::class, ListMembership::class]));
    $context = new ZeroContext(['user_id' => $owner->id]);
    $list = $compiler->compileDeclaration(TodoList::zeroQuery(), $context);
    $todo = $compiler->compileDeclaration(Todo::zeroQuery(), $context);
    expect(data_get($list, 'where.related.subquery.where.right.value'))->toBe($owner->id);
    expect(data_get($todo, 'where.related.subquery.where.related.subquery.where.right.value'))->toBe($owner->id);
});
