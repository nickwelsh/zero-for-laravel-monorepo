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
use ZeroForLaravel\Generation\ScopeCompiler;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Scopes\ScopeRegistry;
use ZeroForLaravel\Support\Input;

/**
 * Mutable, application-owned query construction; never a browser-supplied AST.
 *
 * @property-read HigherOrderQuery $orWhere
 */
final class ZeroQuery implements JsonSerializable
{
    use EloquentPredicates;

    /** @var array{table: string, alias?: string, __visibility?: string, __ability?: string, where?: array<string, mixed>, related?: list<array<string, mixed>>, orderBy?: list<array{string, string}>, limit?: int, start?: array{row: stdClass, exclusive: bool}} */
    private array $ast;

    private ?Condition $condition = null;

    /** @var list<array{string, Condition}> */
    private array $conditionTerms = [];

    private bool $singular = false;

    private ?int $pageSize = null;

    /** @var array{mode: string, name: string}|null */
    private ?array $pagination = null;

    private bool $reversePage = false;

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
        if ($method === 'whereExists') {
            throw new LogicException('Use has() or whereHas(); native exists options are not query declarations.');
        }

        $scope = ScopeRegistry::local($this->model->class, $method);
        if (! $scope instanceof ReflectionMethod) {
            throw new LogicException('Unknown Zero scope '.$this->model->class.'::'.$method.'.');
        }

        $body = ScopeCompiler::local($this->model, $scope, $arguments);
        $this->callScope(function () use ($body): void {
            ScopeCompiler::apply($body, $this);
        });

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
        $this->withoutGlobalScopes(VisibleToCurrentUser::names(new $this->model->class));

        return $this;
    }

    /** @internal Captured policy ability for a route-bound singleton. */
    public function guardedBy(Authenticatable $user, string $ability): self
    {
        $this->visibleTo($user);
        $this->ast['__ability'] = $ability;

        return $this;
    }

    /** @param string|array<array-key, mixed>|Closure(self): (self|void) $field */
    public function where(string|array|Closure $field, mixed $operator = MissingValue::Missing, mixed $value = MissingValue::Missing): self
    {
        return $this->predicate('and', $field, $operator, $value);
    }

    /** @param string|array<array-key, mixed>|Closure(self): (self|void) $field */
    public function orWhere(string|array|Closure $field, mixed $operator = MissingValue::Missing, mixed $value = MissingValue::Missing): self
    {
        return $this->predicate('or', $field, $operator, $value);
    }

    /** @param string|array<array-key, mixed>|Closure(self): (self|void) $field */
    private function predicate(string $boolean, string|array|Closure $field, mixed $operator, mixed $value): self
    {
        if (is_array($field) || $field instanceof Closure) {
            if ($operator !== MissingValue::Missing || $value !== MissingValue::Missing) {
                throw new InvalidArgumentException('Grouped predicates take only the group.');
            }

            $nested = new self($this->model, $this->registry);
            $nested->allScopesRemoved = true;
            if ($field instanceof Closure) {
                $result = $field($nested);
                if ($result !== null && $result !== $nested) {
                    throw new LogicException('A grouped predicate must return the supplied ZeroQuery or void.');
                }
            } else {
                $method = $boolean === 'and' ? 'where' : 'orWhere';
                foreach ($field as $key => $item) {
                    if (is_string($key)) {
                        $nested->{$method}($key, $item);
                    } elseif (is_array($item) && array_is_list($item) && in_array(count($item), [2, 3], true) && is_string($item[0])) {
                        $nested->{$method}(...$item);
                    } else {
                        throw new InvalidArgumentException('Array predicates require field/value pairs or comparison tuples.');
                    }
                }
            }

            return $nested->condition === null ? $this : $this->addCondition($nested->condition, $boolean);
        }

        if ($value === MissingValue::Missing) {
            if ($operator === MissingValue::Missing) {
                throw new InvalidArgumentException('Comparison requires a value.');
            }
            $value = $operator;
            $operator = '=';
        }

        return $this->addCondition($this->expressionBuilder()->cmp($field, ExpressionBuilder::eloquentOperator($operator), $value), $boolean);
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

    public function orderBy(string $field, string $direction = 'asc'): self
    {
        $direction = strtolower($direction);
        if (! isset($this->model->columns[$field]) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Ordering requires an exposed column and asc/desc.');
        }

        $this->assertNotJunction('Ordering');
        $this->ast['orderBy'][] = [$field, $direction];

        return $this;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0 || $limit > QueryLimits::NATIVE_MAX) {
            throw new InvalidArgumentException('Native limits must be non-negative signed 32-bit integers.');
        }

        $this->assertNotJunction('Limit');
        $this->ast['limit'] = $limit;

        return $this;
    }

    /** Finish a singular declaration without consuming the source builder. */
    public function first(): self
    {
        $query = clone $this;
        $query->limit(1);
        $query->singular = true;

        return $query;
    }

    public function find(mixed $id): self
    {
        return (clone $this)->where($this->model->key, $id)->first();
    }

    /** Finish a collection declaration; no SQL, implicit limit or hydration. */
    public function get(): self
    {
        return clone $this;
    }

    public function paginate(int|string|null $perPage = null, string $pageName = 'page'): self
    {
        return (clone $this)->page('numbered', $perPage, $pageName);
    }

    public function simplePaginate(int|string|null $perPage = null, string $pageName = 'page'): self
    {
        return $this->paginate($perPage, $pageName);
    }

    public function cursorPaginate(int|string|null $perPage = null, string $cursorName = 'cursor'): self
    {
        return (clone $this)->page('cursor', $perPage, $cursorName);
    }

    private function page(string $mode, int|string|null $perPage, string $name): self
    {
        $perPage = filter_var($perPage ?? (new $this->model->class)->getPerPage(), FILTER_VALIDATE_INT);
        if ($this->singular || $perPage === false || $perPage < 1 || $perPage >= QueryLimits::liveWindow() || isset($this->ast['limit']) || isset($this->ast['start'])) {
            throw new InvalidArgumentException('Pagination requires an unbounded collection and a positive page size below the live window cap.');
        }

        if (! QueryLimits::validPageName($name)) {
            throw new InvalidArgumentException('A paginator requires a simple, non-reserved URL parameter name.');
        }

        $this->limit($perPage + 1);
        $this->pageSize = $perPage;
        $this->pagination = ['mode' => $mode, 'name' => $name];

        return $this;
    }

    public function pageSize(): ?int
    {
        return $this->pageSize;
    }

    /** @internal Used by Zero::scroll; do not mutate other props sharing the numbered declaration. */
    public function asScroll(): self
    {
        if (($this->pagination['mode'] ?? null) !== 'numbered') {
            throw new LogicException('Zero::scroll requires paginate() or simplePaginate().');
        }

        $query = clone $this;
        $query->pagination = ['mode' => 'scroll', 'name' => $this->pagination['name']];

        return $query;
    }

    /** @return array{mode: string, name: string}|null */
    public function pagination(): ?array
    {
        return $this->pagination;
    }

    /** @internal Apply only validated, mode-specific native window operands.
     * @param array<string, mixed> $position
     */
    public function atPosition(array $position): self
    {
        $mode = $this->pagination['mode'] ?? null;
        Input::keys($position, match ($mode) {
            'numbered' => ['__page'], 'scroll' => ['__page', '__window'], 'cursor' => ['__cursor', '__reverse'], default => [],
        }, 'pagination');
        if ($position === [] || $this->pageSize === null) {
            return $this;
        }

        if ($mode === 'cursor') {
            $cursor = Input::object($position['__cursor'] ?? null, 'cursor');
            $fields = array_unique([...array_column($this->effective()->ast['orderBy'] ?? [], 0), $this->model->key]);
            Input::keys($cursor, array_values($fields), 'cursor');
            if (count($fields) !== count($cursor) || array_key_exists('__reverse', $position) && ! is_bool($position['__reverse'])) {
                Input::fail('cursor', 'Cursor must contain all ordering fields and a boolean traversal direction.');
            }

            $this->reversePage = ($position['__reverse'] ?? false) === true;
            foreach ($cursor as $field => $value) {
                Input::value($value, $this->model->columns[$field], 'cursor.'.$field);
            }
            $this->ast['start'] = ['row' => (object) self::copyValues($cursor), 'exclusive' => true];

            return $this;
        }

        $page = array_key_exists('__page', $position) ? $position['__page'] : 1;
        $cap = QueryLimits::liveWindow();
        if (! is_int($page) || $page < 1 || $page > intdiv($cap - 1, $this->pageSize)) {
            Input::fail('page', 'Page and its lookahead must fit in the live window cap.');
        }

        $prefix = $page * $this->pageSize + 1;
        $window = array_key_exists('__window', $position) ? $position['__window'] : $prefix;
        if (! is_int($window) || $window < $prefix || $window > $cap) {
            Input::fail('window', 'Scroll window must include the starting page and its lookahead, within the live window cap.');
        }

        return $this->limit($window);
    }

    private function expressionBuilder(): ExpressionBuilder
    {
        return new ExpressionBuilder($this->model);
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
            $ast = $this->effective()->ast;
            if ($this->pageSize !== null && ($ast['limit'] ?? null) !== ($this->ast['limit'] ?? null)) {
                throw new LogicException('A global scope cannot override a live page limit.');
            }

            if ($this->pageSize !== null && ! in_array($this->model->key, array_column($ast['orderBy'] ?? [], 0), true)) {
                $ast['orderBy'][] = [$this->model->key, 'asc'];
            }

            if ($this->reversePage) {
                $ast['orderBy'] = array_map(static fn (array $order): array => [$order[0], $order[1] === 'asc' ? 'desc' : 'asc'], $ast['orderBy'] ?? []);
            }

            return self::copyValues($ast);
        } finally {
            self::$renderDepth--;
        }
    }

    /** Construction operands for the generator integration, without executing global scopes.
     * @return array<string, mixed>
     */
    public function constructionAst(): array
    {
        return self::copyValues($this->ast, false);
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
    private static function copyValues(array $values, bool $effective = true): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::copyValues($value, $effective);
            } elseif ($value instanceof self) {
                $values[$key] = $effective ? $value->ast() : $value->constructionAst();
            } elseif ($value instanceof stdClass) {
                $values[$key] = (object) self::copyValues((array) $value, $effective);
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

    /** @internal Attach a compiler-owned predicate, including a validated table filter. */
    public function addCondition(Condition $condition, string $boolean = 'and'): self
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
            if ($before instanceof Condition && in_array('or', array_column($terms, 0), true)) {
                $this->conditionTerms = [['and', $before]];
            }

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
            foreach (ScopeCompiler::globals($this->model) as $key => $scope) {
                if (in_array((string) $key, $this->removedScopes, true)) {
                    continue;
                }

                $query->callScope(function () use ($scope, $query): void {
                    ScopeCompiler::apply($scope, $query);
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
     * @return array{array<string, mixed>, self}
     */
    private function relationship(string $name, ?Closure $refine, bool $predicate): array
    {
        $relation = $this->registry->relation($this->model, $name);
        if (str_starts_with($name, 'zsubq_')) {
            throw new InvalidArgumentException('The zsubq_ relationship prefix is reserved.');
        }

        $path = $relation['path'];
        $junction = count($path) === 2;

        $target = $this->registry->get($relation['model']);
        $child = new self($target, $this->registry, $junction ? $name : null);
        $child->singular = ! $predicate && $relation['cardinality'] === 'one';

        $alias = $predicate ? 'zsubq_'.($junction ? 'zhidden_' : '').$name : $name;
        $child->ast['alias'] = $alias;
        $child->callScope(fn () => $this->model->applyRelationConstraints($name, $child));
        if ($refine !== null) {
            $child->callScope(function () use ($child, $refine): void {
                $result = $refine($child);
                if ($result !== null && $result !== $child) {
                    throw new LogicException('Relationship refinements must return the provided query or void.');
                }
            });
        }

        $ast = $child;
        if ($junction) {
            $inner = ['system' => 'client', 'correlation' => ['parentField' => $path[1]['source'], 'childField' => $path[1]['dest']], 'subquery' => $ast];
            $ast = ['table' => $path[0]['table'], 'alias' => $predicate ? 'zsubq_'.$name : $name];
            if ($predicate) {
                $ast['where'] = ['type' => 'correlatedSubquery', 'related' => $inner, 'op' => 'EXISTS'];
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
