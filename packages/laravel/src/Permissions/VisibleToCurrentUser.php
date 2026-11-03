<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Scopes\ZeroGlobalScope;

/**
 * Opt in with #[ScopedBy([VisibleToCurrentUser::class])].
 *
 * @implements Scope<Model>
 */
final class VisibleToCurrentUser implements Scope, ZeroGlobalScope
{
    /** @param Builder<covariant Model>|ZeroQuery $builder */
    public function apply(Builder|ZeroQuery $builder, Model $model): void
    {
        if ($builder instanceof ZeroQuery) {
            $builder->visibleTo(Auth::user());
        } else {
            $builder->scopes(['visibleTo' => [Auth::user()]]);
        }
    }
}
