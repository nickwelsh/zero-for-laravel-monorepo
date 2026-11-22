<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\ExpressionBuilder;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Support\Input;

/** One checked condition tree feeds both native rows and SQL snapshots.
 * @phpstan-import-type Relation from Table
 */
final readonly class Predicate
{
    /** @param array<string, AllowedFilter> $filters
     * @param  array<string, Relation>  $relations
     */
    public function __construct(private ZeroQuery $base, private array $filters, private array $relations = [], private ?Authenticatable $actor = null) {}

    /** Only compiler-owned conditions may enter this lowering, never request ASTs.
     * @param  array<string, mixed>  $condition
     */
    public static function sql(Builder $query, array $condition, ModelMetadata $model, ?RelationSnapshot $relations = null, ?string $qualifier = null): void
    {
        $kind = $condition['type'] ?? null;
        $qualifier ??= $model->table;
        if ($kind === 'correlatedSubquery' && $relations instanceof RelationSnapshot) {
            $relations->apply($query, $condition, $qualifier);

            return;
        }

        if ($kind === 'and' || $kind === 'or') {
            $children = $condition['conditions'] ?? null;
            if (! is_array($children)) {
                throw new LogicException('Invalid table condition group.');
            }

            $query->where(function (Builder $nested) use ($children, $kind, $model, $relations, $qualifier): void {
                if ($children === [] && $kind === 'or') {
                    $nested->whereRaw('1 = 0');
                }

                foreach ($children as $child) {
                    $method = $kind === 'or' ? 'orWhere' : 'where';
                    $nested->{$method}(fn (Builder $part) => self::sql($part, Input::object($child, 'condition'), $model, $relations, $qualifier));
                }
            });

            return;
        }

        if ($kind !== 'simple') {
            throw new LogicException('Table scope has no portable SQL lowering.');
        }

        $left = Input::object($condition['left'] ?? null, 'condition');
        $right = Input::object($condition['right'] ?? null, 'condition');
        $field = $left['name'] ?? null;
        $op = $condition['op'] ?? null;
        if (($left['type'] ?? null) !== 'column' || ($right['type'] ?? null) !== 'literal' || ! is_string($field) || ! isset($model->columns[$field]) || ! in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'IN', 'IS', 'IS NOT', 'LIKE'], true)) {
            throw new LogicException('Table scope has no portable SQL lowering.');
        }

        $value = $right['value'] ?? null;
        $column = $model->columns[$field];
        if ($column['date'] && $value !== null) {
            $convert = fn (mixed $item): string => is_numeric($item)
                ? CarbonImmutable::createFromTimestampMs((float) $item)->utc()->format('Y-m-d H:i:s.v')
                : CarbonImmutable::parse(is_string($item) ? $item : throw new LogicException('Invalid table date.'))->utc()->format('Y-m-d H:i:s.v');
            $value = is_array($value) ? array_map($convert, $value) : $convert($value);
        }

        $qualified = $qualifier.'.'.$field;
        if ($column['type'] === 'string' && ! $column['date'] && $query->getGrammar() instanceof PostgresGrammar) {
            // Compare replicated text, not PostgreSQL UUID coercion or locale collation.
            $qualified = new readonly class($qualified) implements Expression
            {
                public function __construct(private string $column) {}

                public function getValue(Grammar $grammar): string
                {
                    return 'CAST('.$grammar->wrap($this->column).' AS TEXT) COLLATE "C"';
                }
            };
        }

        if ($op === 'IS' || $op === 'IS NOT') {
            if ($value !== null) {
                throw new LogicException('Portable table IS only supports null.');
            }

            $query->whereNull($qualified, not: $op === 'IS NOT');
        } elseif ($op === 'IN') {
            if (! is_array($value)) {
                throw new LogicException('Invalid table membership.');
            }

            $query->whereIn($qualified, $value);
        } elseif ($op === 'LIKE') {
            if (! $query->getGrammar() instanceof PostgresGrammar || ! is_string($value)) {
                throw new LogicException('Literal case-sensitive table searches require PostgreSQL.');
            }

            $query->whereLike($qualified, $value, caseSensitive: true);
        } else {
            $query->where($qualified, $op, $value);
        }
    }

    public function parse(mixed $input): ?Condition
    {
        $build = $this->check($input);

        return $build instanceof Closure ? $build() : null;
    }

    /** Check the entire tree now. Only executable queries invoke the result,
     * which adds mandatory visibility and scopes to every relationship hop.
     *
     * @return (Closure(): Condition)|null
     */
    public function check(mixed $input): ?Closure
    {
        $terms = 0;

        return $input === null ? null : $this->node($input, 1, $terms);
    }

    /** @return Closure(): Condition */
    private function node(mixed $input, int $depth, int &$terms): Closure
    {
        if ($depth > 8 || ++$terms > 100) {
            Input::fail('filter', 'Filter exceeds depth or condition limits.');
        }

        $node = Input::object($input, 'filter');
        $kind = $node['kind'] ?? null;
        if ($kind === 'and' || $kind === 'or') {
            Input::keys($node, ['kind', 'children'], 'filter');
            $children = $node['children'] ?? null;
            if (! is_array($children) || ! array_is_list($children) || $children === []) {
                Input::fail('filter.children', 'Boolean groups require a nonempty list.');
            }

            $conditions = [];
            foreach ($children as $child) {
                $conditions[] = $this->node($child, $depth + 1, $terms);
            }

            return static fn (): Condition => new Condition($kind, conditions: array_map(static fn (Closure $build): Condition => $build(), $conditions));
        }

        if ($kind === 'relation') {
            return $this->relation($node, $depth, $terms);
        }

        $condition = $this->scalar($node);

        return static fn (): Condition => $condition;
    }

    /** @param array<string, mixed> $node */
    private function scalar(array $node): Condition
    {
        Input::keys($node, ['kind', 'field', 'operator', 'value'], 'filter');
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        $filter = is_string($field) ? ($this->filters[$field] ?? null) : null;
        if (($node['kind'] ?? null) !== 'condition' || $filter === null || ! is_string($operator) || ! in_array($operator, $filter->operations, true)) {
            Input::fail('filter', 'Unknown filter or forbidden operator.');
        }

        $value = $node['value'] ?? null;
        if (in_array($operator, ['isNull', 'isNotNull'], true)) {
            if (array_key_exists('value', $node)) {
                Input::fail('filter.value', 'Null operations have no value.');
            }

            return (new ExpressionBuilder($this->base->model))->cmp($filter->field, $operator === 'isNull' ? 'IS' : 'IS NOT', null);
        }

        if ($operator === 'in') {
            if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
                Input::fail('filter.value', 'Membership requires between 1 and 100 values.');
            }

            $value = array_map(fn (mixed $item): mixed => $this->value($filter, $item), $value);
        } else {
            $value = $this->value($filter, $value);
        }

        $native = match ($operator) {
            'equals' => '=', 'notEquals' => '!=', 'in' => 'IN',
            'gt' => '>', 'gte' => '>=', 'lt' => '<', 'lte' => '<=',
            default => 'LIKE',
        };
        if ($native === 'LIKE') {
            if (! is_string($value) || $value === '') {
                Input::fail('filter.value', 'Text searches require a nonempty string.');
            }

            $value = ($operator === 'startsWith' ? '' : '%').str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).($operator === 'endsWith' ? '' : '%');
        }

        return (new ExpressionBuilder($this->base->model))->cmp($filter->field, $native, $value);
    }

    /** @param array<string, mixed> $node
     * @return Closure(): Condition
     */
    private function relation(array $node, int $depth, int &$terms): Closure
    {
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        if (! is_string($field) || ! isset($this->relations[$field]) || ! in_array($operator, $this->relations[$field]['declaration']->effectiveOperators(), true)) {
            Input::fail('filter', 'Unknown table relation or operator.');
        }

        Input::keys($node, ['kind', 'field', 'operator', $operator === 'some' ? 'filter' : 'value'], 'filter');
        $target = $this->relations[$field]['target'];
        $model = $target->base->model;
        $exists = fn (Condition $condition): Condition => $this->base->relationCondition($field, function (ZeroQuery $related) use ($condition): void {
            if ($related->model->policyVisibility) {
                $related->visibleTo($this->actor);
            }

            $related->addCondition($condition);
        });

        if ($operator === 'some') {
            $parser = new self($target->base, $target->filters, $target->relations, $this->actor);
            $child = $parser->node($node['filter'] ?? null, $depth + 1, $terms);

            return static fn (): Condition => $exists($child());
        }

        $ids = $operator === 'has' ? [$node['value'] ?? null] : ($node['value'] ?? null);
        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > 100) {
            Input::fail('filter.value', 'Relation membership requires between 1 and 100 keys.');
        }

        $ids = array_map(static function (mixed $id) use ($model): int|float|string {
            if ((! is_string($id) && ! is_int($id) && ! is_float($id)) || is_string($id) && ($id === '' || mb_strlen($id) > 1000)) {
                Input::fail('filter.value', 'Invalid relation key.');
            }

            Input::value($id, [...$model->columns[$model->key], 'nullable' => false], 'filter.value');

            return $id;
        }, $ids);

        $comparison = new ExpressionBuilder($model);
        if ($operator === 'has') {
            return static fn (): Condition => $exists($comparison->cmp($model->key, '=', $ids[0]));
        }

        if ($operator === 'any') {
            return static fn (): Condition => $exists($comparison->cmp($model->key, 'IN', $ids));
        }

        $ids = array_values(array_unique($ids, $model->columns[$model->key]['type'] === 'number' ? SORT_NUMERIC : SORT_STRING));
        $terms += count($ids) - 1;
        if ($terms > 100) {
            Input::fail('filter', 'Filter exceeds depth or condition limits.');
        }

        return static fn (): Condition => new Condition('and', conditions: array_map(static fn (mixed $id): Condition => $exists($comparison->cmp($model->key, '=', $id)), $ids));
    }

    private function value(AllowedFilter $filter, mixed $value): mixed
    {
        if (is_string($value) && mb_strlen($value) > 1000) {
            Input::fail('filter.value', 'Filter text exceeds 1000 characters.');
        }

        $column = $this->base->model->columns[$filter->field];
        if ($filter->kind === 'date') {
            if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/D', $value) || strtotime($value) === false || date_parse($value)['warning_count'] > 0) {
                Input::fail('filter.value', 'Dates require an explicit offset and at most millisecond precision.');
            }

            return CarbonImmutable::parse($value)->utc();
        }

        return Input::value($value, [...$column, 'nullable' => false], 'filter.value');
    }
}
