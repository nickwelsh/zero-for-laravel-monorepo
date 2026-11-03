<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use ReflectionMethod;
use stdClass;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\Mutation;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Scopes\ScopeRegistry;
use ZeroForLaravel\Support\Input;

/**
 * Mutable, application-owned query construction; never a browser-supplied AST.
 *
 * @property-read HigherOrderQuery $orWhere
 *
 * @phpstan-type Ast array{table: string, alias?: string, __visibility?: string, __ability?: string, where?: array<string, mixed>, related?: list<array<string, mixed>>, orderBy?: list<array{string, string}>, limit?: int, start?: array{row: stdClass, exclusive: bool}}
 */
final class ZeroQuery implements JsonSerializable
{
    /** @var Ast */
    private array $ast;

    private ?Condition $condition = null;

    /** @var list<array{string, Condition}> */
    private array $conditionTerms = [];

    private bool $singular = false;

    private ?int $pageSize = null;

    private bool $paginated = false;

    /** @var array<string, mixed> */
    private array $relationships = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /** @var list<string> */
    private array $removedScopes = [];

    private bool $allScopesRemoved = false;

    private bool $scopesApplied = false;

    /** @var list<string> */
    private static array $expanding = [];

    private static int $renderDepth = 0;

    public function __construct(
        public readonly ModelMetadata $model,
        private readonly ModelRegistry $registry,
        private readonly ?string $junction = null,
    ) {
        $this->ast = ['table' => $model->table];
    }

    public function __get(string $name): HigherOrderQuery
    {
        if ($name !== 'orWhere') {
            throw new LogicException('Unknown Zero query property '.$name.'.');
        }

        return new HigherOrderQuery($this);
    }

    /** @param array<array-key, mixed> $arguments */
    public function __call(string $method, array $arguments): self
    {
        $scope = ScopeRegistry::local($this->model->class, $method);
        if (! $scope instanceof ReflectionMethod) {
            throw new LogicException('Unknown Zero scope '.$this->model->class.'::'.$method.'.');
        }

        $this->callScope(fn (): mixed => $scope->invoke(new $this->model->class, $this, ...$arguments));

        return $this;
    }

    public function __clone(): void
    {
        if (isset($this->ast['related'])) {
            $this->ast['related'] = array_map(self::cloneValues(...), $this->ast['related']);
        }

        if (isset($this->ast['start'])) {
            $this->ast['start']['row'] = (object) self::cloneValues((array) $this->ast['start']['row']);
        }

        $this->conditionTerms = array_map(fn (array $term): array => [$term[0], self::cloneCondition($term[1])], $this->conditionTerms);
        if ($this->condition instanceof Condition) {
            $this->condition = self::cloneCondition($this->condition);
            $this->ast['where'] = $this->condition->toArray();
        }
    }

    public function visibleTo(?Authenticatable $user): self
    {
        if (! $user instanceof Authenticatable) {
            throw new AuthorizationException('Visibility requires an authenticated actor.');
        }

        if (! $this->model->policyVisibility || ! $user instanceof Model || ! is_a($user, config()->string('permissions.subject.class'))) {
            throw new LogicException('visibleTo requires a bound permission resource and the configured Eloquent actor.');
        }

        $this->ast['__visibility'] = Binding::id($user->getAuthIdentifier());
        $this->withoutGlobalScope(VisibleToCurrentUser::class);

        return $this;
    }

    /** @internal Captured policy ability for a route-bound singleton. */
    public function guardedBy(Authenticatable $user, string $ability): self
    {
        $this->visibleTo($user);
        $this->ast['__ability'] = $ability;

        return $this;
    }

    /** @param string|Closure(self): (self|void) $field */
    public function where(string|Closure $field, mixed $operator = MissingValue::Missing, mixed $value = MissingValue::Missing): self
    {
        $builder = $this->expressionBuilder();
        if ($field instanceof Closure) {
            $nested = new self($this->model, $this->registry);
            $nested->allScopesRemoved = true;
            $result = $field($nested);
            if ($result !== null && $result !== $nested) {
                throw new LogicException('A grouped predicate must return the supplied ZeroQuery or void.');
            }

            $condition = $nested->condition ?? new Condition('and');
        } else {
            $condition = func_num_args() === 2 ? $builder->cmp($field, $operator) : $builder->cmp($field, $operator, $value);
        }

        return $this->addCondition($condition);
    }

    /** @param string|Closure(self): (self|void) $field */
    public function orWhere(string|Closure $field, mixed $operator = MissingValue::Missing, mixed $value = MissingValue::Missing): self
    {
        $nested = new self($this->model, $this->registry);
        func_num_args() === 2 ? $nested->where($field, $operator) : $nested->where($field, $operator, $value);

        return $this->addCondition($nested->condition ?? new Condition('and'), 'or');
    }

    /** @param array<string, mixed> $attributes */
    public function withAttributes(array $attributes, bool $asConditions = true): self
    {
        Input::keys($attributes, $this->model->writable, 'attributes');
        $attributes = array_map(ContextReference::normalize(...), $attributes);
        $this->attributes = [...$this->attributes, ...$attributes];
        if ($asConditions) {
            foreach ($attributes as $field => $value) {
                $this->where($field, $value);
            }
        }

        return $this;
    }

    public function withoutGlobalScope(string $scope): self
    {
        $this->removedScopes[] = $scope;

        return $this;
    }

    /** @param list<string>|null $scopes */
    public function withoutGlobalScopes(?array $scopes = null): self
    {
        if ($scopes === null) {
            $this->allScopesRemoved = true;
        } else {
            foreach ($scopes as $scope) {
                $this->withoutGlobalScope($scope);
            }
        }

        return $this;
    }

    /** @return array<string, mixed> */
    public function pendingAttributes(): array
    {
        return $this->effective()->attributes;
    }

    /**
     * Explicit scope-aware creation for custom/disabled optimism.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes, ?string $key = null): Model
    {
        if ($key === null) {
            throw new LogicException('Outside generated controllers, ZeroQuery::create requires an explicit prepared creation key.');
        }

        return resolve(Mutation::class)->create($this->model->class, $attributes, $key, $this->pendingAttributes());
    }

    /** @param (Closure(self): (self|void))|array<string, bool>|null $refine
     * @param  array<string, bool>  $options
     */
    public function whereExists(string $relationship, Closure|array|null $refine = null, array $options = []): self
    {
        if (is_array($refine)) {
            $options = $refine;
            $refine = null;
        }

        return $this->addCondition($this->expressionBuilder()->exists($relationship, $refine, $options));
    }

    /** @param Closure(self): (self|void)|null $refine */
    public function whereHas(string $relationship, ?Closure $refine = null): self
    {
        return $this->whereExists($relationship, $refine);
    }

    /** @param Closure(self): (self|void)|null $refine */
    public function whereDoesntHave(string $relationship, ?Closure $refine = null): self
    {
        return $this->addCondition($this->expressionBuilder()->not($this->expressionBuilder()->exists($relationship, $refine)));
    }

    /** @param string|array<string, Closure(self): (self|void)>|list<string> $relationship
     * @param  Closure(self): (self|void)|null  $refine
     */
    public function with(string|array $relationship, ?Closure $refine = null): self
    {
        if (is_array($relationship)) {
            foreach ($relationship as $name => $callback) {
                if (is_int($name) && is_string($callback)) {
                    $this->with($callback);
                } elseif (is_string($name) && $callback instanceof Closure) {
                    $this->with($name, $callback);
                } else {
                    throw new InvalidArgumentException('Includes require relationship names or named callbacks.');
                }
            }

            return $this;
        }

        if (str_contains($relationship, '.')) {
            [$head, $tail] = explode('.', $relationship, 2);

            return $this->with($head, fn (self $child): self => $child->with($tail, $refine));
        }

        [$related, $child] = $this->relationship($relationship, $refine, false);
        $this->ast['related'][] = $related;
        $this->relationships[$relationship] = $child->format();

        return $this;
    }

    public function orderBy(string $field, string $direction): self
    {
        if (! isset($this->model->columns[$field]) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Ordering requires an exposed column and asc/desc.');
        }

        $this->assertNotJunction('Ordering');
        $this->ast['orderBy'][] = [$field, $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0 || $limit > 2147483647) {
            throw new InvalidArgumentException('Native limits must be non-negative signed 32-bit integers.');
        }

        $this->assertNotJunction('Limit');
        $this->ast['limit'] = $limit;

        return $this;
    }

    /** @param array<string, mixed> $row
     * @param  array{inclusive?: bool}  $options
     */
    public function start(array $row, array $options = []): self
    {
        Input::keys($row, array_keys($this->model->columns), 'cursor');
        Input::keys($options, ['inclusive'], 'cursor.options');
        foreach ($row as $field => $value) {
            Input::value($value, $this->model->columns[$field], 'cursor.'.$field);
        }

        if (isset($options['inclusive']) && ! is_bool($options['inclusive'])) {
            throw new InvalidArgumentException('Cursor inclusive must be boolean.');
        }

        $this->ast['start'] = ['row' => (object) self::copyValues($row), 'exclusive' => ! ($options['inclusive'] ?? false)];

        return $this;
    }

    public function one(): self
    {
        $this->ast['limit'] = 1;
        $this->singular = true;

        return $this;
    }

    /** Collection cardinality is already the default; no hidden bounds. */
    public function all(): self
    {
        return $this;
    }

    /** A live growing window with one additional row as the exhaustion probe. */
    public function infinite(int $pageSize): self
    {
        if ($this->singular || $pageSize < 1 || $pageSize >= 2147483647 || isset($this->ast['limit']) || isset($this->ast['start'])) {
            throw new InvalidArgumentException('Infinite queries require a collection and a positive page size.');
        }

        $query = $this;
        if (! in_array($this->model->key, array_column($this->ast['orderBy'] ?? [], 0), true)) {
            $query = $query->orderBy($this->model->key, 'asc');
        }

        $query = $query->limit($pageSize + 1);
        $query->pageSize = $pageSize;

        return $query;
    }

    public function pageSize(): ?int
    {
        return $this->pageSize;
    }

    /** Live cursor windows, not numbered snapshots or a total-count paginator. */
    public function paginate(int $pageSize): self
    {
        $query = $this->infinite($pageSize);
        $query->paginated = true;

        return $query;
    }

    public function isPaginated(): bool
    {
        return $this->paginated;
    }

    /** @return list<string> */
    public function cursorFields(): array
    {
        return array_values(array_unique(array_column($this->ast['orderBy'] ?? [], 0)));
    }

    public function expressionBuilder(): ExpressionBuilder
    {
        return new ExpressionBuilder($this->model, function (string $name, ?Closure $refine, array $options): Condition {
            Input::keys($options, ['flip', 'scalar'], 'exists.options');
            foreach ($options as $value) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('Exists options must be boolean.');
                }
            }

            [$related] = $this->relationship($name, $refine, true, $options);

            return new Condition('correlatedSubquery', [
                'related' => $related, 'op' => 'EXISTS',
                ...(isset($options['flip']) ? ['flip' => $options['flip']] : []),
                ...(isset($options['scalar']) && count($this->model->relations[$name]['path']) === 1 ? ['scalar' => $options['scalar']] : []),
            ]);
        });
    }

    /** Raw native construction AST, before server visibility and native normalization.
     * @return array<string, mixed>
     */
    public function ast(): array
    {
        if (++self::$renderDepth > 8) {
            self::$renderDepth--;
            throw new LogicException('Recursive Zero scope/relationship expansion exceeds eight levels at '.$this->model->name.'.');
        }

        try {
            return self::copyValues($this->effective()->ast);
        } finally {
            self::$renderDepth--;
        }
    }

    /** @return array{singular: bool, relationships: stdClass} */
    public function format(): array
    {
        return ['singular' => $this->singular, 'relationships' => (object) self::copyValues($this->relationships)];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Zero query declarations must be extracted before page serialization.');
    }

    /**
     * Own JSON objects as well as arrays, including nested relationship cursors.
     *
     * @template K of array-key
     *
     * @param  array<K, mixed>  $values
     * @return array<K, mixed>
     */
    private static function copyValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::copyValues($value);
            } elseif ($value instanceof self) {
                $values[$key] = $value->ast();
            } elseif ($value instanceof stdClass) {
                $values[$key] = (object) self::copyValues((array) $value);
            }
        }

        return $values;
    }

    private static function cloneCondition(Condition $condition): Condition
    {
        $fields = $condition->fields;
        if (isset($fields['related'])) {
            $fields['related'] = self::cloneValues($fields['related']);
        }

        return new Condition($condition->type, $fields, array_map(self::cloneCondition(...), $condition->conditions));
    }

    /** @template K of array-key
     * @param  array<K, mixed>  $values
     * @return array<K, mixed>
     */
    private static function cloneValues(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = is_array($value) ? self::cloneValues($value) : ($value instanceof self || $value instanceof stdClass ? clone $value : $value);
        }

        return $values;
    }

    private function addCondition(Condition $condition, string $boolean = 'and'): self
    {
        $builder = $this->expressionBuilder();
        $this->conditionTerms[] = [$boolean, $condition];
        $groups = [[]];
        foreach ($this->conditionTerms as [$junction, $term]) {
            if ($junction === 'or' && end($groups) !== []) {
                $groups[] = [];
            }

            $groups[array_key_last($groups)][] = $term;
        }

        $this->condition = ExpressionBuilder::simplify($builder->or(...array_map(fn (array $terms): Condition => $builder->and(...$terms), $groups)));
        $this->ast['where'] = $this->condition->toArray();

        return $this;
    }

    /** @param Closure(): mixed $scope */
    private function callScope(Closure $scope): void
    {
        $before = $this->condition;
        $terms = $this->conditionTerms;
        $this->condition = null;
        $this->conditionTerms = [];
        unset($this->ast['where']);
        $result = $scope();
        if ($result !== null && $result !== $this) {
            throw new LogicException('Zero scopes must return void or the supplied builder.');
        }

        $added = $this->condition;
        $this->condition = $before;
        $this->conditionTerms = $terms;
        if ($before instanceof Condition) {
            $this->ast['where'] = $before->toArray();
        }

        if ($added instanceof Condition) {
            $this->addCondition($added);
        }
    }

    private function effective(): self
    {
        $query = clone $this;
        $query->scopesApplied = true;
        if ($this->scopesApplied || $this->allScopesRemoved) {
            return $query;
        }

        $name = $this->model->class;
        if (in_array($name, self::$expanding, true) || count(self::$expanding) >= 8) {
            throw new LogicException('Recursive Zero scope expansion: '.implode(' -> ', [...self::$expanding, $name]));
        }

        self::$expanding[] = $name;
        try {
            $query->condition = null;
            $query->conditionTerms = [];
            $query->attributes = [];
            unset($query->ast['where']);
            foreach (ScopeRegistry::globals($this->model) as $key => $scope) {
                if (in_array($key, $this->removedScopes, true)) {
                    continue;
                }

                $query->callScope(function () use ($scope, $query, $name): void {
                    $scope instanceof Closure ? $scope($query) : $scope->apply($query, new $name);
                });
            }

            if ($this->condition instanceof Condition) {
                $query->addCondition($this->condition);
            }

            $query->attributes = [...$query->attributes, ...$this->attributes];

            return $query;
        } finally {
            array_pop(self::$expanding);
        }
    }

    /** @param Closure(self): (self|void)|null $refine
     * @param  array<string, bool>  $options
     * @return array{array<string, mixed>, self}
     */
    private function relationship(string $name, ?Closure $refine, bool $predicate, array $options = []): array
    {
        $relation = $this->registry->relation($this->model, $name);
        if (str_starts_with($name, 'zsubq_')) {
            throw new InvalidArgumentException('The zsubq_ relationship prefix is reserved.');
        }

        $path = $relation['path'];
        $junction = count($path) === 2;
        if ($junction && ($options['scalar'] ?? false)) {
            throw new InvalidArgumentException('Scalar exists is only supported for one-hop relationships.');
        }

        $target = $this->registry->get($relation['model']);
        $child = new self($target, $this->registry, $junction ? $name : null);
        $child->singular = ! $predicate && $relation['cardinality'] === 'one';

        $alias = $predicate ? 'zsubq_'.($junction ? 'zhidden_' : '').$name : $name;
        $child->ast['alias'] = $alias;
        $this->model->applyRelationConstraints($name, $child);
        $result = $refine instanceof Closure ? $refine($child) : null;
        if ($result !== null && $result !== $child) {
            throw new LogicException('Relationship refinements must return the provided query or void.');
        }

        $ast = $child;
        if ($junction) {
            $inner = ['system' => 'client', 'correlation' => ['parentField' => $path[1]['source'], 'childField' => $path[1]['dest']], 'subquery' => $ast];
            $ast = ['table' => $path[0]['table'], 'alias' => $predicate ? 'zsubq_'.$name : $name];
            if ($predicate) {
                $ast['where'] = ['type' => 'correlatedSubquery', 'related' => $inner, 'op' => 'EXISTS', ...$options];
            } else {
                $ast['related'] = [$inner];
            }
        }

        return [[
            'system' => 'client',
            'correlation' => ['parentField' => $path[0]['source'], 'childField' => $path[0]['dest']],
            ...($junction && ! $predicate ? ['hidden' => true] : []),
            'subquery' => $ast,
        ], $child];
    }

    private function assertNotJunction(string $operation): void
    {
        if ($this->junction !== null) {
            throw new LogicException("{$operation} is not supported in native junction relationships: {$this->junction}.");
        }
    }
}
