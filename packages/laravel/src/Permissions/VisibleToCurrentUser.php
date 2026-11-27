<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Opt in with #[ScopedBy([VisibleToCurrentUser::class])].
 *
 * @implements Scope<Model>
 */
final class VisibleToCurrentUser implements Scope
{
    /** @return list<string> Registration keys, not assumptions about the names of application scopes. */
    public static function names(Model $model): array
    {
        return array_map(strval(...), array_keys(array_filter($model->getGlobalScopes(), static fn (mixed $scope): bool => $scope instanceof self)));
    }

    /** @param Builder<covariant Model> $builder */
    public function apply(Builder $builder, Model $model): void
    {
        $builder->scopes(['visibleTo' => [Auth::user()]]);
    }
}
