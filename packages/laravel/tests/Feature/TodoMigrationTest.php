<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Middleware;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\PermissionServiceProvider;
use ZeroForLaravel\Permissions\ProjectionManager;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;
use ZeroForLaravel\Tests\Fixtures\Todos\Todo;
use ZeroForLaravel\Tests\Fixtures\Todos\TodoController;
use ZeroForLaravel\Tests\Fixtures\Todos\TodoPolicy;
use ZeroForLaravel\Tests\PostgresTestCase;

uses(PostgresTestCase::class);

beforeEach(function (): void {
    /** @var PostgresTestCase $this */
    DB::beginTransaction();
    app()->register(PermissionServiceProvider::class);
    app()->register(\Inertia\ServiceProvider::class);
    PermissionSchema::create(DB::connection());
    Schema::create('todos', function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->string('title');
        $table->timestamps();
    });
    Schema::create('users', fn (Blueprint $table) => $table->string('id')->primary());
    DB::table('users')->insert(['id' => 'alice']);
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)),
        'permissions.models.default' => dirname(__DIR__, 2).'/config/permissions/global-acl.conf',
        'permissions.resources' => [Todo::class => ['type' => 'todo']],
        'permissions.subject.class' => OwnerUser::class]);
    Gate::policy(Todo::class, TodoPolicy::class);
    (new ProjectionManager)->rebuild();
    foreach (['viewAny', 'view', 'create', 'update', 'delete'] as $ability) {
        Casbin::addPolicy('user:alice', 'todo', $ability, 'any', 'allow');
    }

    Route::middleware(['web', Middleware::class])->resource('todos', TodoController::class)->only(['index', 'show', 'store', 'update', 'destroy']);
    $this->actingAs(OwnerUser::findOrFail('alice'))->withHeader('X-Inertia', 'true');
});

afterEach(function (): void {
    if (getenv('ZERO_TEST_PG_DATABASE')) {
        DB::rollBack();
    }
});

test('the ordinary Todo form preserves its filtered URL or opens the newly created todo', function (): void {
    /** @var PostgresTestCase $this */
    $index = '/todos?status=open&sort=title';
    $this->get($index)->assertOk()->assertJsonPath('component', 'todos/index')->assertJsonCount(0, 'props.todos');
    $this->from($index)->post('/todos', ['title' => 'Stay here'])->assertRedirect($index);
    $this->from($index)->post('/todos', ['title' => ''])->assertRedirect($index)->assertSessionHasErrors('title');
    $response = $this->from($index)->post('/todos', ['title' => 'Open this one', 'open_after_saving' => '1']);
    $todo = Todo::where('title', 'Open this one')->sole();
    $show = route('todos.show', $todo);
    $response->assertRedirect($show);
    expect(DB::table('todos')->count())->toBe(2);
    $this->get($show)->assertOk()->assertJsonPath('component', 'todos/show')->assertJsonPath('props.todo.title', 'Open this one');
    $this->from($show)->post($show.'?_method=PATCH', ['title' => 'Updated'])->assertRedirect($show);
    expect(DB::table('todos')->where('id', $todo->id)->value('title'))->toBe('Updated');
    $this->delete($show)->assertRedirect(route('todos.index'));
    expect(DB::table('todos')->where('id', $todo->id)->exists())->toBeFalse();
});

test('viewAny controls the Todo page independently of visible rows', function (): void {
    /** @var PostgresTestCase $this */
    Todo::create(['title' => 'Existing row']);
    Casbin::removePolicy('user:alice', 'todo', 'view', 'any', 'allow');
    $this->get('/todos')->assertOk()->assertJsonCount(0, 'props.todos');
    Casbin::addPolicy('user:alice', 'todo', 'view', 'any', 'allow');
    Casbin::removePolicy('user:alice', 'todo', 'viewAny', 'any', 'allow');
    expect(Todo::count())->toBe(1);
    $this->get('/todos')->assertNotFound();
});
