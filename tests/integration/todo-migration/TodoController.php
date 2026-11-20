<?php

declare(strict_types=1);

namespace TodoMigration;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Tests\Fixtures\Todos\StoreTodoRequest;
use ZeroForLaravel\Tests\Fixtures\Todos\Todo;
use ZeroForLaravel\Tests\Fixtures\Todos\UpdateTodoRequest;

/** The native sibling of the ordinary HTTP fixture, not a replacement for it. */
final class TodoController
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('viewAny', Todo::class);

        return Inertia::render('todos/index', ['todos' => Todo::zeroQuery()->orderBy('title', 'asc')]);
    }

    public function notes(): Response
    {
        $this->authorize('viewAny', Todo::class);

        return Inertia::render('notes', ['activity' => Todo::zeroQuery()->orderBy('title', 'asc')]);
    }

    public function show(Todo $todo): Response
    {
        $this->authorize('view', $todo);

        return Inertia::render('todos/show', ['todo' => $todo]);
    }

    #[ZeroMutation]
    public function store(StoreTodoRequest $request): RedirectResponse
    {
        $this->authorize('create', Todo::class);
        $attributes = $request->safe()->except(['open_after_saving']);
        $todo = Todo::create($attributes);

        return $request->boolean('open_after_saving') ? to_route('todos.show', ['todo' => $todo]) : back();
    }

    #[ZeroMutation]
    public function update(UpdateTodoRequest $request, Todo $todo): RedirectResponse
    {
        $this->authorize('update', $todo);
        $todo->update($request->validated());

        return back();
    }

    #[ZeroMutation(navigateAfter: 'server')]
    public function destroy(Todo $todo): RedirectResponse
    {
        $this->authorize('delete', $todo);
        $todo->delete();

        return to_route('todos.index');
    }
}
