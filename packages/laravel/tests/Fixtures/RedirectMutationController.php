<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use ZeroForLaravel\Attributes\ZeroMutation;

final class RedirectMutationController
{
    use AuthorizesRequests;

    #[ZeroMutation]
    public function store(RedirectMutationRequest $request): RedirectResponse
    {
        $this->authorize('create', Task::class);
        $todo = Task::create($request->safe()->except(['open_after_saving']));

        return $request->boolean('open_after_saving') ? to_route('todos.show', ['todo' => $todo]) : back();
    }

    #[ZeroMutation(navigateAfter: 'server')]
    public function confirmed(RedirectMutationRequest $request): RedirectResponse
    {
        $todo = Task::create($request->safe()->except(['open_after_saving']));

        return to_route('todos.show', ['todo' => $todo]);
    }

    #[ZeroMutation]
    public function bound(Task $todo): RedirectResponse
    {
        return to_route('todos.show', ['todo' => $todo]);
    }

    #[ZeroMutation]
    public function slug(RedirectMutationRequest $request): RedirectResponse
    {
        $todo = Task::create($request->safe()->except(['open_after_saving']));

        return to_route('todos.title', ['todo' => $todo]);
    }

    #[ZeroMutation(optimism: 'disabled')]
    public function disabled(RedirectMutationRequest $request): RedirectResponse
    {
        Task::create($request->safe()->except(['open_after_saving']));

        return back();
    }

    #[ZeroMutation(optimism: 'custom')]
    public function projected(): RedirectResponse
    {
        return to_route('todos.index');
    }
}
