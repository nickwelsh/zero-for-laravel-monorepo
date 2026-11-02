<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Closure;
use LogicException;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Scopes\ScopeRegistry;
use ZeroForLaravel\Scopes\SoftDeletingScope;

/**
 * Scopes use the controller's checked construction subset, not sampled requests.
 *
 * @phpstan-import-type Expression from \ZeroForLaravel\Generation\Mutations\MutationProgram
 */
final class ScopeCompiler
{
    /** @return array<string, QueryExpression> */
    public static function globals(ModelMetadata $model): array
    {
        $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), $model->registry);
        $scopes = [];
        foreach (ScopeRegistry::globals($model) as $name => $scope) {
            if ($scope instanceof SoftDeletingScope) {
                $local = new QueryExpression('local', 'zeroScope', model: $model, type: 'query');
                $scopes[$name] = new QueryExpression('method', 'where', [$local, self::value($model->deletedAt), self::value('IS'), self::value(null)], $model, 'query');
            } else {
                $scopes[$name] = $compiler->scope($scope instanceof Closure ? $scope : new ReflectionMethod($scope, 'apply'), $model, instance: $scope instanceof Closure ? null : $scope);
            }
        }

        return $scopes;
    }

    public static function value(mixed $value): QueryExpression
    {
        $value = ContextReference::normalize($value);
        if ($value instanceof ContextReference) {
            return new QueryExpression('context', $value->field, type: \ZeroForLaravel\Context\ContextDefinition::configured()->fields[$value->field]['type']);
        }

        if (is_array($value)) {
            $list = array_is_list($value);
            $children = [];
            foreach ($value as $key => $item) {
                $children[] = $list ? self::value($item) : new QueryExpression('property', (string) $key, [self::value($item)]);
            }

            return new QueryExpression($list ? 'list' : 'object', children: $children, type: $list ? 'list' : 'object');
        }

        if ($value !== null && ! is_scalar($value) || is_float($value) && ! is_finite($value)) {
            throw new LogicException('Scope state/captures must be portable scalar values, enum cases, or arrays.');
        }

        return new QueryExpression('literal', $value, type: get_debug_type($value));
    }

    /** @return list<string>|null */
    public static function removals(QueryExpression $query): ?array
    {
        if (! in_array($query->kind, ['method', 'scope', 'infinite', 'paginate'], true)) {
            return [];
        }

        $previous = self::removals($query->children[0]);
        if ($previous === null) {
            return null;
        }

        if ($query->kind === 'method' && $query->value === 'withoutGlobalScope') {
            $name = $query->children[1]->value;
            if (! is_string($name)) {
                throw new LogicException('Global scope removal requires literal scope names/classes.');
            }

            $previous[] = $name;
        } elseif ($query->kind === 'method' && $query->value === 'withoutGlobalScopes') {
            if (! isset($query->children[1]) || $query->children[1]->type === 'null') {
                return null;
            }

            foreach ($query->children[1]->children as $scope) {
                if ($scope->kind !== 'literal' || ! is_string($scope->value)) {
                    throw new LogicException('Global scope removal requires literal scope names/classes.');
                }

                $previous[] = $scope->value;
            }
        }

        return $previous;
    }

    public static function attributes(QueryExpression $query, bool $globals = true): QueryExpression
    {
        if ($query->kind === 'conditional') {
            return new QueryExpression('conditional', children: [$query->children[0], self::attributes($query->children[1], $globals), self::attributes($query->children[2], $globals)], type: 'object');
        }

        $layers = [];
        $removed = self::removals($query);
        if ($globals && $removed !== null) {
            foreach (self::globals($query->model ?? throw new LogicException('Creation attributes require a model query.')) as $name => $scope) {
                if (! in_array($name, $removed, true)) {
                    $layers[] = self::attributes($scope, false);
                }
            }
        }

        $collect = function (QueryExpression $node) use (&$collect, &$layers): void {
            if ($node->kind === 'conditional') {
                $layers[] = self::attributes($node, false);
            } elseif (in_array($node->kind, ['method', 'scope', 'infinite', 'paginate'], true)) {
                $collect($node->children[0]);
                if ($node->kind === 'scope') {
                    $layers[] = self::attributes($node->children[1], false);
                } elseif ($node->kind === 'method' && $node->value === 'withAttributes') {
                    $layers[] = $node->children[1];
                }
            }
        };
        $collect($query);

        return new QueryExpression('merge', children: $layers, type: 'object');
    }

    /** @return array<string, mixed> */
    public static function defaults(ModelMetadata $model): array
    {
        return self::mutation(self::attributes(new QueryExpression('query', model: $model, type: 'query')));
    }

    /** @return Expression */
    public static function mutation(QueryExpression $value): array
    {
        $children = array_map(self::mutation(...), $value->children);

        return match ($value->kind) {
            'literal' => ['op' => 'literal', 'value' => $value->value],
            'context' => ['op' => 'context', 'field' => $value->value],
            'merge' => ['op' => 'merge', 'values' => $children],
            'object', 'list' => ['op' => $value->kind, 'items' => array_map(fn (QueryExpression $child): array => ['key' => $child->kind === 'property' ? $child->value : null, 'value' => self::mutation($child->kind === 'property' ? $child->children[0] : $child)], $value->children)],
            'property' => $children[0],
            'conditional' => ['op' => 'conditional', 'condition' => $children[0], 'yes' => $children[1], 'no' => $children[2]],
            'argument', 'local' => ['op' => 'local', 'name' => $value->value],
            'validated' => ['op' => 'validated', 'base' => ['op' => 'local', 'name' => $value->value]],
            'field' => ['op' => 'get', 'base' => $children[0], 'key' => ['op' => 'literal', 'value' => $value->value]],
            'binary' => ['op' => 'binary', 'operator' => $value->value, 'left' => $children[0], 'right' => $children[1]],
            default => throw new LogicException('Nonportable creation attribute expression '.$value->kind),
        };
    }
}
