<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveTodoRequest;
use App\Models\Todo;
use App\Models\TodoList;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Permissions\ProjectionManager;

final class TodoController extends Controller
{
    /** @return array{id: string} */
    #[ZeroMutation(optimism: 'disabled', serverResult: 'array{id: string}')]
    public function store(SaveTodoRequest $request): array
    {
        $todo = DB::transaction(function () use ($request): Todo {
            (new ProjectionManager)->lock();
            $list = TodoList::query()->lockForUpdate()->findOrFail($request->input('list_id'));
            Gate::authorize('create', [Todo::class, $list]);

            return Todo::create($request->validated());
        });

        return ['id' => $todo->id];
    }

    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function update(SaveTodoRequest $request, Todo $todo): void
    {
        DB::transaction(function () use ($request, $todo): void {
            (new ProjectionManager)->lock();
            $todo = Todo::query()->lockForUpdate()->findOrFail($todo->id);
            Gate::authorize('update', $todo);
            if ($request->exists('list_id')) {
                $list = TodoList::query()->lockForUpdate()->findOrFail($request->input('list_id'));
                Gate::authorize('create', [Todo::class, $list]);
            }

            $todo->update($request->validated());
        });
    }

    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function destroy(Todo $todo): void
    {
        DB::transaction(function () use ($todo): void {
            (new ProjectionManager)->lock();
            $todo = Todo::query()->lockForUpdate()->findOrFail($todo->id);
            Gate::authorize('delete', $todo);
            $todo->delete();
        });
    }
}
