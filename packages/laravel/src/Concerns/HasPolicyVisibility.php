<?php

declare(strict_types=1);

namespace ZeroForLaravel\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use ZeroForLaravel\Permissions\EloquentVisibility;

trait HasPolicyVisibility
{
    /** @param Builder<static> $query
     * @return Builder<static>
     */
    protected function scopeVisibleTo(Builder $query, Authenticatable $user): Builder
    {
        return (new EloquentVisibility)->apply($query, $user);
    }
}
