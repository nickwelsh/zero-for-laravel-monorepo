<?php

use App\Http\Controllers\DashboardController;
use App\Models\Todo;
use App\Models\TodoList;
use App\Models\User;
use App\Permissions\ListPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\TypeScriptGenerator;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\ZeroContext;

test('dashboard declarations explicitly protect roots and descendants without exposing authority columns', function () {
    $queries = app(ControllerQueryCompiler::class)->compile(DashboardController::class, 'index');
    expect($queries)->toHaveCount(1);
    expect(substr_count($queries[0]->php(), '->visibleTo('))->toBe(4);
    $browser = (new TypeScriptGenerator)->generate(app(ModelRegistry::class));
    expect($browser)->not->toContain('list_memberships', 'permission_rules', 'permission_subjects', 'permission_index', 'password', 'email');
});

test('maintained list and todo visibility agrees with Gates through sharing revocation filters and rollback', function () {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Maintained policy visibility requires PostgreSQL 15+.');
    }

    $owner = User::factory()->create();
    $reader = User::factory()->create();
    $outsider = User::factory()->create();
    $shared = createTodoList($owner, 'Shared');
    $private = createTodoList($owner, 'Private');
    $todo = Todo::create(['list_id' => $shared->id, 'title' => 'Milk']);
    Todo::create(['list_id' => $private->id, 'title' => 'Private todo']);
    ListPermissions::grantReader($shared, $reader->id);

    expect(TodoList::query()->visibleTo($reader)->pluck('id')->all())->toBe([$shared->id]);
    expect(TodoList::query()->visibleTo($outsider)->count())->toBe(0);
    expect(Todo::query()->visibleTo($reader)->where('completed', false)->pluck('id')->all())->toBe([$todo->id]);
    expect(Todo::query()->visibleTo($reader)->where('completed', true)->count())->toBe(0);
    foreach (TodoList::all() as $list) {
        expect(TodoList::query()->visibleTo($reader)->whereKey($list->id)->exists())
            ->toBe(Gate::forUser($reader)->allows('view', $list));
    }

    $compiler = new QueryCompiler(app(ModelRegistry::class));
    $context = new ZeroContext(['user_id' => $reader->id]);
    $ast = $compiler->compileDeclaration(TodoList::zeroQuery()->visibleTo($reader)
        ->related('todos', fn ($todos) => $todos->visibleTo($reader)), $context);
    $encoded = json_encode($ast, JSON_THROW_ON_ERROR);
    expect($encoded)->toContain('permission_resources_', 'permission_index', 'user:'.$reader->id);

    expect(fn () => DB::transaction(function () use ($shared, $reader): void {
        ListPermissions::revokeReader($shared, $reader->id);
        expect(TodoList::query()->visibleTo($reader)->count())->toBe(0);
        expect(Todo::query()->visibleTo($reader)->count())->toBe(0);
        throw new RuntimeException('Rollback');
    }))->toThrow(RuntimeException::class, 'Rollback');
    expect(TodoList::query()->visibleTo($reader)->count())->toBe(1);
    ListPermissions::revokeReader($shared, $reader->id);
    expect(TodoList::query()->visibleTo($reader)->count())->toBe(0);
    expect(Todo::query()->visibleTo($reader)->count())->toBe(0);
    expect(TodoList::query()->visibleTo($owner)->count())->toBe(2);
});
