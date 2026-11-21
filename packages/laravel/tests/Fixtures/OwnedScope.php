<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use ZeroForLaravel\Context\ContextReference;

/** @implements Scope<Model> */
final class OwnedScope implements Scope
{
    public function apply(Builder $query, Model $model): void
    {
        $query->withAttributes(['user_id' => ContextReference::current(ZeroContext::USER_ID)]);
    }
}
