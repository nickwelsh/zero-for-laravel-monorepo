<?php

declare(strict_types=1);

namespace ZeroForLaravel\Scopes;

use Closure;
use Illuminate\Database\Eloquent\Attributes\Scope;
use LogicException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ZeroForLaravel\Attributes\ZeroScope;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\ZeroQuery;

final class ScopeRegistry
{
    public static function local(string $class, string $name): ?ReflectionMethod
    {
        if (! method_exists($class, $name)) {
            return null;
        }

        $method = new ReflectionMethod($class, $name);
        if ($method->getAttributes(ZeroScope::class) === []) {
            return null;
        }

        if ($method->isPublic() || $method->isStatic() || $method->getAttributes(Scope::class) !== [] || method_exists($class, 'scope'.ucfirst($name)) || method_exists(\Illuminate\Database\Eloquent\Builder::class, $name) || method_exists(ZeroQuery::class, $name)) {
            throw new LogicException($class.'::'.$name.': Zero scopes must be nonpublic instance methods with unambiguous names.');
        }

        $type = ($method->getParameters()[0] ?? null)?->getType();
        if (! $type instanceof ReflectionNamedType || $type->getName() !== ZeroQuery::class) {
            throw new LogicException($class.'::'.$name.': a Zero scope requires a ZeroQuery first parameter.');
        }

        $return = $method->getReturnType();
        if (! $return instanceof ReflectionNamedType || ! in_array($return->getName(), ['void', ZeroQuery::class], true)) {
            throw new LogicException($class.'::'.$name.': a Zero scope must declare void or ZeroQuery.');
        }

        return $method;
    }

    /** @return array<string, Closure|ZeroGlobalScope> */
    public static function globals(ModelMetadata $metadata): array
    {
        $scopes = [];
        $classes = [];
        for ($class = new ReflectionClass($metadata->class); $class; $class = $class->getParentClass()) {
            array_unshift($classes, $class);
        }

        foreach ($classes as $class) {
            foreach ($class->getAttributes(ZeroScopedBy::class) as $attribute) {
                foreach ($attribute->newInstance()->scopes as $scope) {
                    $instance = resolve($scope);
                    if (! $instance instanceof ZeroGlobalScope) {
                        throw new LogicException($scope.' must implement ZeroGlobalScope.');
                    }

                    $scopes[$scope] = $instance;
                }
            }

            $name = $class->name;
            if (! method_exists($name, 'getZeroGlobalScopes')) {
                continue;
            }

            $registered = $name::getZeroGlobalScopes();
            if (! is_array($registered)) {
                throw new LogicException('getZeroGlobalScopes must return a scope map.');
            }

            foreach ($registered as $name => $scope) {
                if (! is_string($name) || ! $scope instanceof Closure && ! $scope instanceof ZeroGlobalScope) {
                    throw new LogicException('Zero global scopes must be named closures or ZeroGlobalScope instances.');
                }

                $scopes[$name] = $scope;
            }
        }

        if ($metadata->deletedAt !== null) {
            $scopes[SoftDeletingScope::class] = new SoftDeletingScope;
        }

        return $scopes;
    }

    public static function fingerprint(ModelMetadata $model): string
    {
        $sources = [];
        foreach ((new ReflectionClass($model->class))->getMethods() as $method) {
            if ($method->getAttributes(ZeroScope::class) !== []) {
                self::local($model->class, $method->name);
                $sources[$method->class.'::'.$method->name] = hash_file('sha256', $method->getFileName() ?: throw new LogicException('Zero scopes require inspectable source.'));
            }
        }

        foreach (self::globals($model) as $name => $scope) {
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
                $sources[$name.':'.$key] = \ZeroForLaravel\Generation\ScopeCompiler::value($value)->emit(false);
            }
        }

        return hash('sha256', serialize($sources));
    }
}
