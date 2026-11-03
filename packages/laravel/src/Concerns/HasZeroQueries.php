<?php

declare(strict_types=1);

namespace ZeroForLaravel\Concerns;

use Closure;
use LogicException;
use ReflectionMethod;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Scopes\ScopeRegistry;
use ZeroForLaravel\Scopes\ZeroGlobalScope;

trait HasZeroQueries
{
    /** @var array<class-string, array<string, Closure|ZeroGlobalScope>> */
    protected static array $zeroGlobalScopes = [];

    /** @param string $method
     * @param  array<array-key, mixed>  $parameters
     */
    public function __call($method, $parameters): mixed
    {
        if (ScopeRegistry::local(static::class, $method) instanceof ReflectionMethod) {
            return static::zeroQuery()->{$method}(...$parameters);
        }

        return parent::__call($method, $parameters);
    }

    /** @param string $method
     * @param  array<array-key, mixed>  $parameters
     */
    public static function __callStatic($method, $parameters): mixed
    {
        // Eloquent's parent implementation can see protected child methods;
        // intercept here before it invokes a Zero scope without its builder.
        if (ScopeRegistry::local(static::class, $method) instanceof ReflectionMethod) {
            return static::zeroQuery()->__call($method, $parameters);
        }

        return parent::__callStatic($method, $parameters);
    }

    public static function zeroQuery(): ZeroQuery
    {
        $registry = resolve(ModelRegistry::class);
        foreach ($registry->all() as $model) {
            if ($model->class === static::class) {
                return new ZeroQuery($model, $registry);
            }
        }

        throw new LogicException('Register '.static::class.' as a Zero model before declaring queries.');
    }

    public static function addZeroGlobalScope(string|ZeroGlobalScope $scope, ?Closure $implementation = null): void
    {
        if ($scope instanceof ZeroGlobalScope) {
            static::$zeroGlobalScopes[static::class][$scope::class] = $scope;
        } elseif ($implementation instanceof Closure) {
            static::$zeroGlobalScopes[static::class][$scope] = $implementation;
        } else {
            throw new LogicException('Named Zero scopes require a closure.');
        }
    }

    /** @return array<string, Closure|ZeroGlobalScope> */
    public static function getZeroGlobalScopes(): array
    {
        return static::$zeroGlobalScopes[static::class] ?? [];
    }
}
