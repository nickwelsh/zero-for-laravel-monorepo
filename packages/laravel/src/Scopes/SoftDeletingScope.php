<?php

declare(strict_types=1);

namespace ZeroForLaravel\Scopes;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ZeroForLaravel\Query\ZeroQuery;

final class SoftDeletingScope implements ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void
    {
        $column = $query->model->deletedAt ?? throw new LogicException('A Zero soft-delete scope requires a deleted-at column.');
        $query->where($column, 'IS', null);
    }
}
