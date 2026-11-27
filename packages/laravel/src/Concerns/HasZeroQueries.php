<?php

declare(strict_types=1);

namespace ZeroForLaravel\Concerns;

use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\ZeroQuery;

trait HasZeroQueries
{
    public static function zeroQuery(): ZeroQuery
    {
        $registry = resolve(ModelRegistry::class);
        foreach ($registry->all() as $model) {
            if ($model->class === static::class) {
                return ZeroQuery::forModel($model);
            }
        }

        throw new LogicException('Register '.static::class.' as a Zero model before declaring queries.');
    }
}
