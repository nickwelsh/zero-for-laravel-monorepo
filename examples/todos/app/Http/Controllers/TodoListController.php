<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveTodoListRequest;
use App\Models\TodoList;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

final class TodoListController extends Controller
{
    /** @return array{id: string} */
    #[ZeroMutation]
    public function store(SaveTodoListRequest $request): array
    {
        Gate::authorize('create', TodoList::class);
        $list = TodoList::create($request->validated());

        return ['id' => $list->id];
    }

    #[ZeroMutation(conceal: true)]
    public function update(SaveTodoListRequest $request, TodoList $list, Mutation $mutation): void
    {
        $list = $mutation->reloadForUpdate($list);
        Gate::authorize('update', $list);
        $list->update($request->validated());
    }

    #[ZeroMutation(conceal: true)]
    public function destroy(TodoList $list, Mutation $mutation): void
    {
        $list = $mutation->reloadForUpdate($list);
        Gate::authorize('delete', $list);
        $list->delete();
    }
}
