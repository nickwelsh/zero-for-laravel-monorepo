<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShareListRequest;
use App\Models\TodoList;
use App\Models\User;
use App\Permissions\ListPermissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Permissions\ProjectionManager;

class ShareListController extends Controller
{
    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function __invoke(ShareListRequest $request, TodoList $list): void
    {
        DB::transaction(function () use ($request, $list): void {
            (new ProjectionManager)->lock();
            $list = TodoList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::authorize('share', $list);
            Mutation::server(function () use ($request, $list): void {
                $user = User::where('email', $request->string('email')->toString())->firstOrFail();
                ListPermissions::grantReader($list, $user->id);
            });
        });
    }

    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function destroy(ShareListRequest $request, TodoList $list): void
    {
        DB::transaction(function () use ($request, $list): void {
            (new ProjectionManager)->lock();
            $list = TodoList::query()->lockForUpdate()->findOrFail($list->id);
            Gate::authorize('share', $list);
            Mutation::server(function () use ($request, $list): void {
                $user = User::where('email', $request->string('email')->toString())->firstOrFail();
                ListPermissions::revokeReader($list, $user->id);
            });
        });
    }
}
