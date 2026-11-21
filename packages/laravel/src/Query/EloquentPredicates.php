<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Context\ContextReference;

/** Eloquent conveniences build the same predicates as ordinary where groups. */
trait EloquentPredicates
{
    public function whereKey(mixed $id): self
    {
        if (is_array($id)) {
            if (! array_is_list($id)) {
                throw new InvalidArgumentException('whereKey requires a scalar key or list of keys.');
            }

            return $this->whereIn($this->model->key, $id);
        }

        return $this->where($this->model->key, $id);
    }

    /** @param string|list<string> $columns */
    public function whereNull(string|array $columns): self
    {
        return $this->nullPredicate($columns, 'and', false);
    }

    /** @param string|list<string> $columns */
    public function whereNotNull(string|array $columns): self
    {
        return $this->nullPredicate($columns, 'and', true);
    }

    /** @param string|list<string> $columns */
    public function orWhereNull(string|array $columns): self
    {
        return $this->nullPredicate($columns, 'or', false);
    }

    /** @param string|list<string> $columns */
    public function orWhereNotNull(string|array $columns): self
    {
        return $this->nullPredicate($columns, 'or', true);
    }

    /** @param list<mixed> $values */
    public function whereIn(string $column, array $values): self
    {
        return $this->membership($column, $values, 'and', false);
    }

    /** @param list<mixed> $values */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->membership($column, $values, 'and', true);
    }

    /** @param list<mixed> $values */
    public function orWhereIn(string $column, array $values): self
    {
        return $this->membership($column, $values, 'or', false);
    }

    /** @param list<mixed> $values */
    public function orWhereNotIn(string $column, array $values): self
    {
        return $this->membership($column, $values, 'or', true);
    }

    /** @param list<mixed> $values */
    public function whereBetween(string $column, array $values): self
    {
        return $this->between($column, $values, 'and', false);
    }

    /** @param list<mixed> $values */
    public function whereNotBetween(string $column, array $values): self
    {
        return $this->between($column, $values, 'and', true);
    }

    /** @param list<mixed> $values */
    public function orWhereBetween(string $column, array $values): self
    {
        return $this->between($column, $values, 'or', false);
    }

    /** @param list<mixed> $values */
    public function orWhereNotBetween(string $column, array $values): self
    {
        return $this->between($column, $values, 'or', true);
    }

    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'desc');
    }

    public function latest(?string $column = null): self
    {
        return $this->orderBy($column ?? $this->model->createdAt ?? 'created_at', 'desc');
    }

    public function oldest(?string $column = null): self
    {
        return $this->orderBy($column ?? $this->model->createdAt ?? 'created_at');
    }

    public function take(int $limit): self
    {
        return $this->limit($limit);
    }

    /** @param Closure(self, bool): (self|void) $callback
     * @param  (Closure(self, bool): (self|void))|null  $default
     */
    public function when(bool $value, Closure $callback, ?Closure $default = null): self
    {
        $branch = $value ? $callback : $default;
        $result = $branch === null ? null : $branch($this, $value);
        if ($result !== null && $result !== $this) {
            throw new LogicException('Conditional callbacks must return the supplied builder or void.');
        }

        return $this;
    }

    /** @param Closure(self, bool): (self|void) $callback
     * @param  (Closure(self, bool): (self|void))|null  $default
     */
    public function unless(bool $value, Closure $callback, ?Closure $default = null): self
    {
        return $this->when($value, $default ?? static fn () => null, $callback);
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function has(string $relation, string $operator = '>=', int $count = 1, string $boolean = 'and', ?Closure $callback = null): self
    {
        $not = ExpressionBuilder::absence($operator, $count);
        if (! in_array($boolean, ['and', 'or'], true)) {
            throw new InvalidArgumentException('Relationship predicates require and/or.');
        }
        if (str_contains($relation, '.')) {
            [$relation, $tail] = explode('.', $relation, 2);
            // Laravel's doesntHave(path) negates the whole path. Other count
            // comparisons on a dotted path belong to its final relationship.
            $outerNot = $operator === '<' && $count === 1;
            $leaf = $callback;
            $callback = fn (self $child): self => $outerNot ? $child->whereHas($tail, $leaf) : $child->whereHas($tail, $leaf, $operator, $count);
            $not = $outerNot;
        }
        [$related] = $this->relationship($relation, $callback, true);

        return $this->addCondition(new Condition('correlatedSubquery', ['related' => $related, 'op' => $not ? 'NOT EXISTS' : 'EXISTS']), $boolean);
    }

    public function orHas(string $relation, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'or');
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function doesntHave(string $relation, string $boolean = 'and', ?Closure $callback = null): self
    {
        return $this->has($relation, '<', 1, $boolean, $callback);
    }

    public function orDoesntHave(string $relation): self
    {
        return $this->doesntHave($relation, 'or');
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function whereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'and', $callback);
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function orWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        return $this->has($relation, $operator, $count, 'or', $callback);
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function whereDoesntHave(string $relation, ?Closure $callback = null): self
    {
        return $this->doesntHave($relation, 'and', $callback);
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function orWhereDoesntHave(string $relation, ?Closure $callback = null): self
    {
        return $this->doesntHave($relation, 'or', $callback);
    }

    public function whereRelation(string $relation, string $column, mixed $operator, mixed $value = MissingValue::Missing): self
    {
        return $this->whereHas($relation, fn (self $child): self => $child->where($column, $operator, $value));
    }

    public function orWhereRelation(string $relation, string $column, mixed $operator, mixed $value = MissingValue::Missing): self
    {
        return $this->orWhereHas($relation, fn (self $child): self => $child->where($column, $operator, $value));
    }

    /** @param (Closure(self): (self|void))|null $callback */
    public function withWhereHas(string $relation, ?Closure $callback = null, string $operator = '>=', int $count = 1): self
    {
        return $this->whereHas($relation, $callback, $operator, $count)->with($relation, $callback);
    }

    /** @param string|list<string> $columns */
    private function nullPredicate(string|array $columns, string $boolean, bool $not): self
    {
        foreach ((array) $columns as $column) {
            $this->predicate($boolean, $column, $not ? '!=' : '=', null);
        }

        return $this;
    }

    /** @param list<mixed> $values */
    private function membership(string $column, array $values, string $boolean, bool $not): self
    {
        $builder = $this->expressionBuilder();
        if (! array_is_list($values)) {
            throw new InvalidArgumentException('Membership requires a scalar list.');
        }
        foreach ($values as $value) {
            $reference = ContextReference::normalize($value);
            if ($reference instanceof ContextReference && ContextDefinition::configured()->fields[$reference->field]['nullable']) {
                throw new InvalidArgumentException('Membership cannot depend on a nullable context operand.');
            }
        }
        $nonNull = array_values(array_filter($values, static fn (mixed $v): bool => $v !== null));
        $condition = $builder->cmp($column, $not ? 'NOT IN' : 'IN', $nonNull);
        // SQL's NOT IN with a null member is never true. Empty NOT IN, on
        // the other hand, even accepts a row whose column is null.
        if ($nonNull === [] || $not && count($values) !== count($nonNull)) {
            $condition = $builder->cmpLit(1, '=', $not && $values === [] ? 1 : 0);
        }

        return $this->addCondition($condition, $boolean);
    }

    /** @param list<mixed> $values */
    private function between(string $column, array $values, string $boolean, bool $not): self
    {
        if (! array_is_list($values) || count($values) !== 2) {
            throw new InvalidArgumentException('Between requires two ordered bounds.');
        }
        if ($values[0] === null || $values[1] === null) {
            if (! $not || $values === [null, null]) {
                return $this->membership($column, [], $boolean, false);
            }

            return $this->predicate($boolean, $column, $values[0] === null ? '>' : '<', $values[0] ?? $values[1]);
        }

        $builder = $this->expressionBuilder();
        $condition = $builder->and($builder->cmp($column, '>=', $values[0]), $builder->cmp($column, '<=', $values[1]));

        return $this->addCondition($not ? $builder->not($condition) : $condition, $boolean);
    }
}
