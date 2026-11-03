<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Support\Input;

/** Native Zero 1.11.3 expression semantics. Conditions are compiler-owned values. */
final readonly class ExpressionBuilder
{
    private const array NEGATIONS = [
        '=' => '!=', '!=' => '=', '<' => '>=', '>' => '<=', '>=' => '<', '<=' => '>',
        'IN' => 'NOT IN', 'NOT IN' => 'IN', 'LIKE' => 'NOT LIKE', 'NOT LIKE' => 'LIKE',
        'ILIKE' => 'NOT ILIKE', 'NOT ILIKE' => 'ILIKE', 'IS' => 'IS NOT', 'IS NOT' => 'IS',
        'EXISTS' => 'NOT EXISTS', 'NOT EXISTS' => 'EXISTS',
    ];

    /** @param Closure(string, ?Closure, array<string, bool>): Condition $exists */
    public function __construct(private ModelMetadata $model, private Closure $exists) {}

    public static function simplify(Condition $condition): Condition
    {
        $type = $condition->type;
        if (! in_array($type, ['and', 'or'], true)) {
            return $condition;
        }

        if (count($condition->conditions) === 1) {
            return self::simplify($condition->conditions[0]);
        }

        $children = [];
        foreach ($condition->conditions as $child) {
            $child = self::simplify($child);
            if ($child->isEmptyGroup($type === 'and' ? 'or' : 'and')) {
                return $child;
            }

            array_push($children, ...($child->type === $type ? $child->conditions : [$child]));
        }

        return new Condition($type, conditions: $children);
    }

    public function cmp(string $field, mixed $operator, mixed $value = MissingValue::Missing): Condition
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $column = $this->model->columns[$field] ?? null;
        if ($column === null || $column['type'] === 'json') {
            throw new InvalidArgumentException("Unknown or compound comparison column {$this->model->name}.{$field}.");
        }

        $operator = $this->operator($operator);
        $value = ContextReference::normalize($value);
        if ($value === null && in_array($operator, ['=', '!='], true)) {
            $operator = $operator === '=' ? 'IS' : 'IS NOT';
        }

        if ($column['date'] && (is_string($value) || $value instanceof DateTimeInterface)) {
            $date = CarbonImmutable::parse($value, 'UTC')->utc();
            $value = isset($column['dateFormat']) ? $date->format($column['dateFormat']) : $date->getTimestampMs();
        }

        if (! $value instanceof Parameter && ! $value instanceof ContextReference && $value !== MissingValue::Missing) {
            if (in_array($operator, ['IN', 'NOT IN'], true)) {
                if (! is_array($value) || ! array_is_list($value)) {
                    throw new InvalidArgumentException('Membership requires a scalar list.');
                }

                foreach ($value as &$item) {
                    if ($item instanceof ContextReference) {
                        continue;
                    }

                    if ($column['date'] && (is_string($item) || $item instanceof DateTimeInterface)) {
                        $date = CarbonImmutable::parse($item, 'UTC')->utc();
                        $item = isset($column['dateFormat']) ? $date->format($column['dateFormat']) : $date->getTimestampMs();
                    }

                    Input::value($item, [...$column, 'nullable' => false], $field);
                }

                unset($item);
            } elseif ($value !== null || ! in_array($operator, ['IS', 'IS NOT'], true)) {
                Input::value($value, [...$column, 'nullable' => false], $field);
            }
        }

        return new Condition('simple', ['left' => ['type' => 'column', 'name' => $field], 'right' => $this->operand($value), 'op' => $operator]);
    }

    public function cmpLit(mixed $left, string $operator, mixed $right): Condition
    {
        $operator = $this->operator($operator);

        return new Condition('simple', ['left' => $this->operand($left), 'right' => $this->operand($right), 'op' => $operator]);
    }

    /**
     * @param  Closure(ZeroQuery): (ZeroQuery|void)|null  $refine
     * @param  array<string, bool>  $options
     */
    public function exists(string $relationship, ?Closure $refine = null, array $options = []): Condition
    {
        return ($this->exists)($relationship, $refine, $options);
    }

    public function and(Condition|MissingValue ...$conditions): Condition
    {
        return $this->group('and', array_values($conditions));
    }

    public function or(Condition|MissingValue ...$conditions): Condition
    {
        return $this->group('or', array_values($conditions));
    }

    public function not(Condition $condition): Condition
    {
        if (in_array($condition->type, ['and', 'or'], true)) {
            return new Condition($condition->type === 'and' ? 'or' : 'and', conditions: array_map($this->not(...), $condition->conditions));
        }

        $operator = self::NEGATIONS[$condition->fields['op'] ?? ''] ?? throw new InvalidArgumentException('Unknown condition operator.');

        return new Condition($condition->type, [...$condition->fields, 'op' => $operator]);
    }

    /** @param list<Condition|MissingValue> $conditions */
    private function group(string $type, array $conditions): Condition
    {
        $children = [];
        foreach ($conditions as $condition) {
            if ($condition instanceof Condition && ! $condition->isEmptyGroup($type)) {
                $children[] = $condition;
            }
        }

        if (count($children) === 1) {
            return $children[0];
        }

        foreach ($children as $condition) {
            if ($condition->isEmptyGroup($type === 'and' ? 'or' : 'and')) {
                return $condition;
            }
        }

        return new Condition($type, conditions: $children);
    }

    private function operator(mixed $operator): string
    {
        if (! is_string($operator) || ! isset(self::NEGATIONS[$operator]) || in_array($operator, ['EXISTS', 'NOT EXISTS'], true)) {
            throw new InvalidArgumentException('Unknown native comparison operator.');
        }

        return $operator;
    }

    /** @return array<string, mixed> */
    private function operand(mixed $value): array
    {
        if ($value instanceof Parameter) {
            return $value->toArray();
        }

        $value = ContextReference::normalize($value);
        if ($value instanceof ContextReference) {
            return ['type' => 'literal', 'value' => $value];
        }

        if ($value === MissingValue::Missing) {
            $value = null;
        }

        $values = is_array($value) && array_is_list($value) ? $value : [$value];
        foreach ($values as $item) {
            if (! $item instanceof ContextReference && $item !== null && ! is_string($item) && ! is_bool($item) && ! is_int($item) && (! is_float($item) || ! is_finite($item))) {
                throw new InvalidArgumentException('Native operands must be finite scalars or scalar lists.');
            }
        }

        return ['type' => 'literal', 'value' => $value];
    }
}
