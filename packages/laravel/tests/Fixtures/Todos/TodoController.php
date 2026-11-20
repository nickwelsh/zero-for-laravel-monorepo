<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Todos;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Ordinary HTTP/Inertia baseline for the imports-only migration. */
final class TodoController
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('viewAny', Todo::class);

        return Inertia::render('todos/index', ['todos' => Todo::all()]);
    }

    public function show(Todo $todo): Response
    {
        $this->authorize('view', $todo);

        return Inertia::render('todos/show', ['todo' => $todo]);
    }

    public function store(StoreTodoRequest $request): RedirectResponse
    {
        $this->authorize('create', Todo::class);
        /** @var array{title: string} $attributes */
        $attributes = $request->safe()->except(['open_after_saving']);
        $todo = Todo::create($attributes);

        return $request->boolean('open_after_saving') ? to_route('todos.show', ['todo' => $todo]) : back();
    }

    public function update(UpdateTodoRequest $request, Todo $todo): RedirectResponse
    {
        $this->authorize('update', $todo);
        $todo->update($request->validated());

        return back();
    }

    public function destroy(Todo $todo): RedirectResponse
    {
        $this->authorize('delete', $todo);
        $todo->delete();

        return to_route('todos.index');
    }
}
