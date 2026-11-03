<?php

declare(strict_types=1);

namespace ZeroForLaravel\Concerns;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use ZeroForLaravel\Permissions\EloquentVisibility;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;

trait HasPolicyVisibility
{
    /** @param Builder<static> $query
     * @return Builder<static>
     */
    protected function scopeVisibleTo(Builder $query, ?Authenticatable $user): Builder
    {
        if (! $user instanceof Authenticatable) {
            throw new AuthorizationException('Visibility requires an authenticated actor.');
        }

        $query->withoutGlobalScope(VisibleToCurrentUser::class);

        return (new EloquentVisibility)->apply($query, $user);
    }
}
