<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use Illuminate\Database\Eloquent\Model;
use LogicException;
use ReflectionClass;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Support\ApplicationClasses;

final readonly class ModelDiscovery
{
    public function __construct(private ApplicationClasses $classes) {}

    /** @return list<class-string<Model>> */
    public function registered(): array
    {
        $models = [];
        foreach ($this->classes->all() as $class) {
            if (! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            if (method_exists($class, 'zero')) {
                throw new LogicException($class.': legacy zero()/ZeroDefinition must be migrated to HasZeroQueries and Zero scopes.');
            }

            if (in_array(HasZeroQueries::class, class_uses_recursive($class), true)) {
                $models[] = $class;
            }
        }

        return $models;
    }
}
