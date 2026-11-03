<?php

declare(strict_types=1);

namespace ZeroForLaravel\Scopes;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Query\ZeroQuery;

interface ZeroGlobalScope
{
    public function apply(ZeroQuery $query, Model $model): void;
}
