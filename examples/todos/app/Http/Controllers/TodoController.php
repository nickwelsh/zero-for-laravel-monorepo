<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveTodoRequest;
use App\Models\Todo;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

final class TodoController extends Controller
{
    /** @return array{id: string} */
    #[ZeroMutation]
    public function store(SaveTodoRequest $request): array
    {
        Gate::authorize('create', Todo::class);
        $todo = Todo::create($request->validated());

        return ['id' => $todo->id];
    }

    #[ZeroMutation(conceal: true)]
    public function update(SaveTodoRequest $request, Todo $todo, Mutation $mutation): void
    {
        $todo = $mutation->reloadForUpdate($todo);
        Gate::authorize('update', $todo);
        $todo->update($request->validated());
    }

    #[ZeroMutation(conceal: true)]
    public function destroy(Todo $todo, Mutation $mutation): void
    {
        $todo = $mutation->reloadForUpdate($todo);
        Gate::authorize('delete', $todo);
        $todo->delete();
    }
}
