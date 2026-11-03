<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

final class PermissionQueryController
{
    public function index(Request $request): Response
    {
        return Inertia::render('lists/index', ['lists' => PermissionList::zeroQuery()->visibleTo($request->user())->orderBy('id', 'asc')->paginate(2)]);
    }

    public function show(PermissionList $list): Response
    {
        Gate::authorize('view', $list);

        return Inertia::render('lists/show', ['list' => $list]);
    }

    public function unscoped(): Response
    {
        return Inertia::render('lists/index', ['lists' => PermissionList::zeroQuery()]);
    }

    public function forged(Request $request): Response
    {
        $actor = $request->user('admin');
        if (! $actor instanceof PermissionUser) {
            abort(403);
        }

        return Inertia::render('lists/index', ['lists' => PermissionList::zeroQuery()->visibleTo($actor)]);
    }
}
