<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class ModelDiscovery
{
    /** @return list<class-string<Model>> */
    public function registered(): array
    {
        $models = config()->array('zero.models');
        if (! array_is_list($models)) {
            throw new LogicException('Zero models must be a list of Eloquent model classes.');
        }

        $classes = [];
        foreach ($models as $model) {
            if (! is_string($model) || ! is_subclass_of($model, Model::class)) {
                throw new LogicException('Zero models must be a list of Eloquent model classes.');
            }

            $classes[] = $model;
        }

        return array_values(array_unique($classes));
    }
}
