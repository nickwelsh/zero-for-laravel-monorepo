<?php

declare(strict_types=1);

namespace ZeroForLaravel\Scopes;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope as GlobalScope;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionObject;
use ZeroForLaravel\Generation\ScopeCompiler;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\ZeroQuery;

final class ScopeRegistry
{
    public static function local(string $class, string $name): ?ReflectionMethod
    {
        // A concrete builder operation wins over model scope dispatch in Laravel.
        if (method_exists(Builder::class, $name) || method_exists(ZeroQuery::class, $name)) {
            return null;
        }

        if (method_exists($class, $name)) {
            $method = new ReflectionMethod($class, $name);
            if (! $method->isPrivate() && $method->getAttributes(Scope::class) !== []) {
                return $method;
            }
        }

        $legacy = 'scope'.ucfirst($name);
        $method = method_exists($class, $legacy) ? new ReflectionMethod($class, $legacy) : null;

        return $method?->isPrivate() ? null : $method;
    }

    /** @return array<int|string, Closure|GlobalScope<*>> */
    public static function globals(ModelMetadata $metadata): array
    {
        $scopes = [];
        foreach ((new $metadata->class)->getGlobalScopes() as $name => $scope) {
            if (! $scope instanceof Closure && ! $scope instanceof GlobalScope) {
                throw new LogicException($metadata->class.': invalid Eloquent global scope.');
            }

            $scopes[$name] = $scope;
        }

        return $scopes;
    }

    public static function fingerprint(ModelMetadata $model): string
    {
        $sources = [];
        foreach ((new ReflectionClass($model->class))->getMethods() as $method) {
            if ($method->isPrivate() || str_starts_with($method->class, 'Illuminate\\')) {
                continue;
            }

            $name = $method->getAttributes(Scope::class) !== [] ? $method->name
                : (str_starts_with($method->name, 'scope') ? lcfirst(substr($method->name, 5)) : '');
            if ($name !== '' && self::local($model->class, $name)?->name === $method->name) {
                $sources[$method->class.'::'.$method->name] = hash_file('sha256', $method->getFileName() ?: throw new LogicException('Zero scopes require inspectable source.'));
            }
        }

        foreach (self::globals($model) as $name => $scope) {
            // Unnamed closures are keyed by a process-local object id in Laravel.
            $name = is_int($name) || $scope instanceof Closure && $name === spl_object_hash($scope) ? '#'.count($sources) : $name;
            // This translation is ours; framework source and internal state are not contract inputs.
            if ($scope::class === SoftDeletingScope::class) {
                $sources[$name] = [SoftDeletingScope::class, $model->deletedAt];

                continue;
            }

            $reflection = $scope instanceof Closure ? new ReflectionFunction($scope) : new ReflectionMethod($scope, 'apply');
            $sources[$name] = hash_file('sha256', $reflection->getFileName() ?: throw new LogicException('Zero scopes require inspectable source.'));
            $state = $scope instanceof Closure ? $reflection->getStaticVariables() : [];
            if (! $scope instanceof Closure) {
                foreach ((new ReflectionObject($scope))->getProperties() as $property) {
                    if (! $property->isStatic() && $property->isInitialized($scope)) {
                        $state[$property->name] = $property->getValue($scope);
                    }
                }
            }

            foreach ($state as $key => $value) {
                $sources[$name.':'.$key] = ScopeCompiler::value($value)->emit(false);
            }
        }

        return hash('sha256', serialize($sources));
    }
}
