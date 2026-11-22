<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Carbon\CarbonImmutable;
use Closure;
use Formbraid\Laravel\OptionInput;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use LogicException;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Formbraid\OptionPattern;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Scopes\ScopeRegistry;

/**
 * Scopes use the controller's checked construction subset, not sampled requests.
 *
 * @phpstan-import-type Expression from \ZeroForLaravel\Generation\Mutations\MutationProgram
 */
final class ScopeCompiler
{
    /** @return array<int|string, QueryExpression> */
    public static function globals(ModelMetadata $model): array
    {
        $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), $model->registry);
        $scopes = [];
        foreach (ScopeRegistry::globals($model) as $name => $scope) {
            if ($scope::class === SoftDeletingScope::class) {
                $local = new QueryExpression('local', 'zeroScope', model: $model, type: 'query');
                $scopes[$name] = new QueryExpression('method', 'where', [$local, self::value($model->deletedAt ?? throw new LogicException('Missing soft-delete column.')), self::value(null)], $model, 'query');
            } elseif ($scope instanceof VisibleToCurrentUser) {
                $local = new QueryExpression('local', 'zeroScope', model: $model, type: 'query');
                $scopes[$name] = new QueryExpression('method', 'visibleTo', [$local, new QueryExpression('actor', type: 'actor')], $model, 'query');
            } else {
                if (method_exists($scope, 'extend')) {
                    throw new LogicException($scope::class.': Eloquent builder extensions are not portable.');
                }

                $scopes[$name] = $compiler->scope($scope instanceof Closure ? $scope : new ReflectionMethod($scope, 'apply'), $model, instance: $scope instanceof Closure ? null : $scope);
            }
        }

        return $scopes;
    }

    public static function creationOnly(QueryExpression $scope): bool
    {
        return match ($scope->kind) {
            'local' => true,
            'scope' => self::creationOnly($scope->children[0]) && self::creationOnly($scope->children[1]),
            'method' => $scope->value === 'withAttributes' && ($scope->children[2]->value ?? true) === false && self::creationOnly($scope->children[0]),
            default => false,
        };
    }

    /** @param array<array-key, mixed> $arguments */
    public static function local(ModelMetadata $model, ReflectionMethod $scope, array $arguments): QueryExpression
    {
        if (! array_is_list($arguments)) {
            throw new LogicException('Scope arguments must be positional.');
        }

        $compiler = new ControllerQueryCompiler((new ParserFactory)->createForNewestSupportedVersion(), $model->registry);

        return $compiler->scope($scope, $model, array_map(self::value(...), $arguments));
    }

    /** Check that the scope can run on both builders and report whether it adds a predicate. */
    public static function assertPortable(QueryExpression $node): bool
    {
        if (! in_array($node->kind, ['method', 'scope', 'local', 'callback', 'literal', 'actor', 'context', 'list', 'object', 'property', 'argument', 'validated', 'field', 'enum'], true) || $node->isTerminal()) {
            throw new LogicException('Unsupported Eloquent scope expression: '.$node->kind.'.');
        }

        $children = array_map(self::assertPortable(...), $node->children);
        if ($node->kind !== 'method') {
            return $node->kind === 'scope' && ($children[0] || $children[1]) || $node->kind === 'callback' && $children[0];
        }

        $method = $node->stringValue();
        if (str_starts_with($method, 'orWhere') && ! $children[0]) {
            throw new LogicException('An Eloquent scope must group its alternatives instead of starting with orWhere.');
        }

        return $children[0] || str_starts_with($method, 'where') && ($node->children[1]->kind !== 'callback' || $children[1])
            || $method === 'withAttributes' && ($node->children[2]->value ?? true) && $node->children[1]->children !== [];
    }

    /** Replay the same checked refinements the native emitter sees, never application PHP against a pretend Eloquent builder. */
    public static function apply(QueryExpression $scope, ZeroQuery $query): void
    {
        self::replay($scope, ['zeroScope' => $query]);
    }

    /** Compile date literals once in UTC. Never freeze a relative date into a generated operation. */
    public static function dateValue(mixed $value, ?string $format): QueryExpression
    {
        if (is_array($value) && array_is_list($value)) {
            return new QueryExpression('list', children: array_map(fn (mixed $item): QueryExpression => self::dateValue($item, $format), $value), type: 'list');
        }

        if (is_string($value)) {
            $parts = date_parse($value);
            if ($parts['year'] === false || $parts['month'] === false || $parts['day'] === false || isset($parts['relative']) || $parts['error_count'] || $parts['warning_count']) {
                throw new InvalidArgumentException('Date literals must be valid absolute dates.');
            }

            $date = CarbonImmutable::parse($value, 'UTC')->utc();
            $value = $format === null ? $date->getTimestampMs() : $date->format($format);
        }

        return self::value($value);
    }

    public static function value(mixed $value): QueryExpression
    {
        $value = ContextReference::normalize($value);
        if ($value instanceof OptionInput) {
            return new QueryExpression('optionInput', json_encode($value->toArray(), JSON_THROW_ON_ERROR), type: 'option');
        }

        if ($value instanceof OptionPattern) {
            return new QueryExpression('optionContains', children: [self::value($value->input)], type: 'string');
        }

        if ($value instanceof ContextReference) {
            return new QueryExpression('context', $value->field, type: ContextDefinition::configured()->fields[$value->field]['type']);
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
        if (! in_array($query->kind, ['method', 'scope', 'infinite', 'paginate', 'guarded'], true)) {
            return [];
        }

        $previous = self::removals($query->children[0]);
        if ($previous === null) {
            return null;
        }

        if ($query->kind === 'scope') {
            $body = self::removals($query->children[1]);

            return $body === null ? null : [...$previous, ...$body];
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
            } elseif (in_array($node->kind, ['method', 'scope', 'infinite', 'paginate', 'guarded'], true)) {
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

    /** @param array<string, ZeroQuery> $locals */
    private static function replay(QueryExpression $node, array $locals): mixed
    {
        $read = fn (QueryExpression $child): mixed => self::replay($child, $locals);
        if ($node->kind === 'scope' || $node->kind === 'method') {
            $builder = $read($node->children[0]);
            if (! $builder instanceof ZeroQuery) {
                throw new LogicException('Scope operations must refine their supplied builder.');
            }

            return $builder->{$node->stringValue()}(...array_map($read, array_slice($node->children, $node->kind === 'scope' ? 2 : 1)));
        }

        return match ($node->kind) {
            'local' => $locals[$node->stringValue()],
            'literal' => $node->value,
            'actor' => Auth::user(),
            'context' => new ContextReference($node->stringValue()),
            'callback' => fn (ZeroQuery $builder): mixed => self::replay($node->children[0], [...$locals, $node->stringValue() => $builder]),
            'list' => array_map($read, $node->children),
            'object' => array_column(array_map(fn (QueryExpression $item): array => [$item->stringValue(), $read($item->children[0])], $node->children), 1, 0),
            default => throw new LogicException('Unsupported Eloquent scope operand: '.$node->kind.'.'),
        };
    }
}
