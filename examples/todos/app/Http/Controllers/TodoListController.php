<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\SaveTodoListRequest;
use App\Models\TodoList;
use App\Permissions\ListPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Permissions\ProjectionManager;

final class TodoListController extends Controller
{
    public function show(TodoList $list): Response
    {
        Gate::authorize('view', $list);

        return Inertia::render('lists/show', ['list' => $list]);
    }

    /** @return array{id: string} */
    #[ZeroMutation(optimism: 'disabled', serverResult: 'array{id: string}')]
    public function store(SaveTodoListRequest $request): array
    {
        Gate::authorize('create', TodoList::class);
        $list = DB::transaction(function () use ($request): TodoList {
            (new ProjectionManager)->lock();
            $list = TodoList::create([
                ...$request->validated(),
                'user_id' => $request->user()->getAuthIdentifier(),
            ]);
            Mutation::server(function () use ($list): void {
                ListPermissions::grantOwner($list);
            });

            return $list;
        });

        return ['id' => $list->id];
    }

    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function update(SaveTodoListRequest $request, TodoList $list): void
    {
        DB::transaction(function () use ($request, $list): void {
            // Permission writes and source writes always take the same lock first.
            (new ProjectionManager)->lock();
            $list = TodoList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::authorize('update', $list);
            $list->update($request->validated());
        });
    }

    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function destroy(TodoList $list): void
    {
        DB::transaction(function () use ($list): void {
            (new ProjectionManager)->lock();
            $list = TodoList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::authorize('delete', $list);
            Mutation::server(function () use ($list): void {
                ListPermissions::forget($list);
            });
            $list->delete();
        });
    }
}
