<?php

namespace App\Http\Controllers;

use App\Http\Requests\ShareListRequest;
use App\Models\TodoList;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

class ShareListController extends Controller
{
    #[ZeroMutation(optimism: 'disabled', conceal: true)]
    public function __invoke(ShareListRequest $request, TodoList $list, Mutation $mutation): void
    {
        $list = $mutation->reloadForUpdate($list);
        Gate::authorize('share', $list);
        $user = User::where('email', $request->string('email')->toString())->firstOrFail();
        $list->memberships()->firstOrCreate(['user_id' => $user->id]);
    }
}
