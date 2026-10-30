<?php

declare(strict_types=1);

namespace ZeroForLaravel\Concerns;

use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\Query;

trait HasZeroQueries
{
    public static function zeroQuery(): Query
    {
        $registry = resolve(ModelRegistry::class);
        foreach ($registry->all() as $model) {
            if ($model->class === static::class) {
                return new Query($model, $registry);
            }
        }

        throw new LogicException('Register '.static::class.' as a Zero model before declaring queries.');
    }
}
