<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\RedirectResponse;
use ZeroForLaravel\Attributes\ZeroMutation;

final class InvalidRedirectMutationController
{
    #[ZeroMutation]
    public function dynamic(string $route): RedirectResponse
    {
        return to_route($route);
    }

    #[ZeroMutation]
    public function missingParameter(): RedirectResponse
    {
        return to_route('todos.show');
    }

    #[ZeroMutation]
    public function extraParameter(): RedirectResponse
    {
        return to_route('todos.index', ['next' => 'example']);
    }

    #[ZeroMutation(optimism: 'disabled')]
    public function unsupported(): RedirectResponse
    {
        return back()->with('status', 'saved');
    }

    #[ZeroMutation]
    public function invalidCondition(RedirectMutationRequest $request): RedirectResponse
    {
        return $request->boolean('unvalidated') ? to_route('todos.index') : back();
    }

    #[ZeroMutation]
    public function wrongMethod(): RedirectResponse
    {
        return to_route('todos.write');
    }

    #[ZeroMutation(navigateAfter: 'server')]
    public function json(): string
    {
        return 'ok';
    }
}
