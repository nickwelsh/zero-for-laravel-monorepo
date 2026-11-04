<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Support\Input;

/** One checked condition tree feeds both native rows and SQL snapshots. */
final readonly class Predicate
{
    /** @param array<string, AllowedFilter> $filters */
    public function __construct(private ZeroQuery $base, private array $filters) {}

    public static function zero(ZeroQuery $query, Condition $condition): void
    {
        if ($condition->type === 'simple') {
            $field = Input::object($condition->fields['left'] ?? null, 'condition')['name'] ?? null;
            $value = Input::object($condition->fields['right'] ?? null, 'condition')['value'] ?? null;
            if (! is_string($field)) {
                throw new LogicException('Table conditions require a field.');
            }

            $query->where($field, $condition->fields['op'] ?? throw new LogicException('Missing table comparison.'), $value);

            return;
        }

        $query->where(function (ZeroQuery $nested) use ($condition): void {
            foreach ($condition->conditions as $child) {
                $method = $condition->type === 'or' ? 'orWhere' : 'where';
                $nested->{$method}(fn (ZeroQuery $part) => self::zero($part, $child));
            }
        });
    }

    /** Only compiler-owned conditions may enter this lowering, never request ASTs.
     * @param  array<string, mixed>  $condition
     */
    public static function sql(Builder $query, array $condition, ModelMetadata $model): void
    {
        $kind = $condition['type'] ?? null;
        if ($kind === 'and' || $kind === 'or') {
            $children = $condition['conditions'] ?? null;
            if (! is_array($children)) {
                throw new LogicException('Invalid table condition group.');
            }

            $query->where(function (Builder $nested) use ($children, $kind, $model): void {
                if ($children === [] && $kind === 'or') {
                    $nested->whereRaw('1 = 0');
                }

                foreach ($children as $child) {
                    $method = $kind === 'or' ? 'orWhere' : 'where';
                    $nested->{$method}(fn (Builder $part) => self::sql($part, Input::object($child, 'condition'), $model));
                }
            });

            return;
        }

        $left = Input::object($condition['left'] ?? null, 'condition');
        $right = Input::object($condition['right'] ?? null, 'condition');
        $field = $left['name'] ?? null;
        $op = $condition['op'] ?? null;
        if ($kind !== 'simple' || ($left['type'] ?? null) !== 'column' || ($right['type'] ?? null) !== 'literal' || ! is_string($field) || ! isset($model->columns[$field]) || ! in_array($op, ['=', '!=', '>', '>=', '<', '<=', 'IN', 'IS', 'IS NOT', 'LIKE'], true)) {
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

        $qualified = $model->table.'.'.$field;
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
        $terms = 0;

        return $input === null ? null : $this->node($input, 1, $terms);
    }

    private function node(mixed $input, int $depth, int &$terms): Condition
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

            return new Condition($kind, conditions: $conditions);
        }

        Input::keys($node, ['kind', 'field', 'operator', 'value'], 'filter');
        $field = $node['field'] ?? null;
        $operator = $node['operator'] ?? null;
        $filter = is_string($field) ? ($this->filters[$field] ?? null) : null;
        if ($kind !== 'condition' || $filter === null || ! is_string($operator) || ! in_array($operator, $filter->operations, true)) {
            Input::fail('filter', 'Unknown filter or forbidden operator.');
        }

        $value = $node['value'] ?? null;
        if (in_array($operator, ['isNull', 'isNotNull'], true)) {
            if (array_key_exists('value', $node)) {
                Input::fail('filter.value', 'Null operations have no value.');
            }

            return $this->base->expressionBuilder()->cmp($filter->field, $operator === 'isNull' ? 'IS' : 'IS NOT', null);
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

        return $this->base->expressionBuilder()->cmp($filter->field, $native, $value);
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
