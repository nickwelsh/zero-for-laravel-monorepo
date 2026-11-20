<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use LogicException;

/** Invocation-local Request/Auth identity, restored even when authorization throws. */
final class ActorScope
{
    public static function fresh(Request $request): Model&Authenticatable
    {
        $class = config()->string('permissions.subject.class');
        if (! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
            throw new LogicException('Access checks require an authenticatable permission subject model.');
        }

        $user = $request->user();
        if (! $user instanceof $class) {
            throw new AuthenticationException;
        }

        $subject = new $class;
        (new ProjectionManager)->assertConnection($subject);
        $actor = $subject->newQuery()->whereKey($user->getAuthIdentifier())->first();
        if (! $actor instanceof Model || ! $actor instanceof Authenticatable) {
            throw new AuthenticationException;
        }

        return $actor;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function run(Request $request, Authenticatable $actor, Closure $callback): mixed
    {
        $default = Auth::getDefaultDriver();
        $guard = Auth::guard($default);
        $previous = $guard->user();
        if ($previous === null && ! method_exists($guard, 'forgetUser')) {
            throw new LogicException('Permission checks require a restorable authentication guard.');
        }

        $authResolver = Auth::userResolver();
        $resolver = $request->getUserResolver();
        try {
            $request->setUserResolver(function (?string $name = null) use ($actor, $default, $resolver): ?Authenticatable {
                $user = $name === null || $name === $default ? $actor : $resolver($name);
                if ($user !== null && ! $user instanceof Authenticatable) {
                    throw new LogicException('Invalid authentication user resolver.');
                }

                return $user;
            });
            Auth::resolveUsersUsing(fn (?string $name = null): mixed => $name === null || $name === $default ? $actor : $authResolver($name));
            $guard->setUser($actor);

            return $callback();
        } finally {
            // SessionGuard dispatches Authenticated from setUser: listeners must
            // see the caller's identity, even if setup itself was interrupted.
            $request->setUserResolver($resolver);
            Auth::shouldUse($default);
            Auth::resolveUsersUsing($authResolver);
            try {
                if ($previous !== null) {
                    $guard->setUser($previous);
                } elseif (method_exists($guard, 'forgetUser')) {
                    $guard->forgetUser();
                }
            } finally {
                // A restoration listener may change guards/resolvers and throw.
                $request->setUserResolver($resolver);
                Auth::shouldUse($default);
                Auth::resolveUsersUsing($authResolver);
            }
        }
    }
}
