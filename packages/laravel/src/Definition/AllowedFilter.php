<?php

declare(strict_types=1);

namespace ZeroForLaravel\Definition;

use Closure;
use LogicException;
use ZeroForLaravel\Enums\FilterOperator;

final readonly class AllowedFilter
{
    /** @param list<AllowedFilter> $members
     * @param  array<string, array<string, mixed>>  $conditions
     */
    private function __construct(public string $name, public string $kind, public string $field, public ?string $operator = null, public array $members = [], public ?Closure $callback = null, public int $maxItems = 1000, public array $conditions = [], public string $prefix = '', public string $suffix = '') {}

    /** Callback builds a portable ZQL predicate. Use a model column as $field for a typed input, or boolean by default. */
    public static function callback(string $name, Closure $callback, ?string $field = null): self
    {
        return new self($name, 'callback', $field ?? '', callback: $callback);
    }

    /** @param list<AllowedFilter> $members */
    public static function groupOr(string $name, array $members): self
    {
        return self::group($name, $members, 'or');
    }

    /** @param list<AllowedFilter> $members */
    public static function groupAnd(string $name, array $members): self
    {
        return self::group($name, $members, 'and');
    }

    public static function operator(string $name, FilterOperator $operator, ?string $field = null): self
    {
        return new self($name, 'operator', $field ?? $name, $operator->value);
    }

    public static function beginsWithStrict(string $name, ?string $field = null): self
    {
        return new self($name, 'beginsWith', $field ?? $name);
    }

    public static function endsWithStrict(string $name, ?string $field = null): self
    {
        return new self($name, 'endsWith', $field ?? $name);
    }

    /** Trusted wildcard pattern around an escaped scalar input. */
    public static function pattern(string $name, string $field, string $prefix = '', string $suffix = ''): self
    {
        return new self($name, 'pattern', $field, prefix: $prefix, suffix: $suffix);
    }

    public static function exact(string $name, ?string $field = null): self
    {
        return new self($name, 'exact', $field ?? $name);
    }

    public static function partial(string $name, ?string $field = null): self
    {
        return new self($name, 'partial', $field ?? $name);
    }

    public static function belongsTo(string $relation): self
    {
        return new self($relation, 'belongsTo', $relation);
    }

    /** A bounded set of scalar values, suitable for ranked search hydration. */
    public static function in(string $name, ?string $field = null, int $max = 1000): self
    {
        if ($max < 1 || $max > 1000) {
            throw new LogicException('Set filters require a maximum between 1 and 1000.');
        }

        return new self($name, 'in', $field ?? $name, maxItems: $max);
    }

    /** Match a declared relation's scalar column, preserving the related model's authorization. */
    public static function related(string $name, string $relation, string $field): self
    {
        return new self($name, 'related', $relation, operator: $field);
    }

    /** A bounded all/any group of explicitly allowed filters.
     * @param  list<AllowedFilter>  $filters
     */
    public static function conditions(string $name, array $filters, int $max = 10): self
    {
        if ($filters === [] || $max < 1 || $max > 100) {
            throw new LogicException('Condition groups need filters and a maximum between 1 and 100.');
        }

        $conditions = [];
        foreach ($filters as $filter) {
            if (! $filter instanceof self || ! in_array($filter->kind, ['exact', 'partial', 'beginsWith', 'endsWith', 'operator', 'related', 'group', 'pattern'], true) || isset($conditions[$filter->name])) {
                throw new LogicException('Condition groups require distinct scalar or relationship filters.');
            }

            $conditions[$filter->name] = $filter->toArray();
        }

        return new self($name, 'conditions', '', maxItems: $max, conditions: $conditions);
    }

    public static function trashed(): self
    {
        return new self('trashed', 'trashed', '');
    }

    /** @return array{kind: string, field: string, operator?: string, members?: list<array<string, mixed>>, conditions?: array<string, array<string, mixed>>, maxItems?: int, prefix?: string, suffix?: string, callback?: Closure, expression?: array<string, mixed>} */
    public function toArray(): array
    {
        return [...(in_array($this->kind, ['in', 'conditions'], true) ? ['maxItems' => $this->maxItems] : []), ...($this->conditions !== [] ? ['conditions' => $this->conditions] : []), ...($this->kind === 'pattern' ? ['prefix' => $this->prefix, 'suffix' => $this->suffix] : []), 'kind' => $this->kind, 'field' => $this->field, ...($this->operator !== null ? ['operator' => $this->operator] : []), ...($this->members !== [] ? ['members' => array_map(fn (self $member): array => $member->toArray(), $this->members)] : []), ...($this->callback instanceof Closure ? ['callback' => $this->callback] : [])];
    }

    /** @param list<AllowedFilter> $members */
    private static function group(string $name, array $members, string $operator): self
    {
        if ($members === []) {
            throw new LogicException('Filter groups must not be empty.');
        }

        foreach ($members as $member) {
            if (! in_array($member->kind, ['exact', 'partial', 'beginsWith', 'endsWith', 'operator', 'pattern'], true)) {
                throw new LogicException('Filter groups require scalar member filters.');
            }
        }

        return new self($name, 'group', $members[0]->field, $operator, $members);
    }
}
