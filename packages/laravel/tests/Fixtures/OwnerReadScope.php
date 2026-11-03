<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Scopes\ZeroGlobalScope;

final class OwnerReadScope implements ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void
    {
        $query->where('user_id', ZeroContext::USER_ID);
    }
}
