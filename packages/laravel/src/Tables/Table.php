<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Generation\ScopeCompiler;
use ZeroForLaravel\Metadata\JunctionLookup;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\EloquentVisibility;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\QueryLimits;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/**
 * Mutable, application-owned capabilities. Build a fresh definition per request.
 *
 * @phpstan-type Group array{field: string, relation: string|null, label: string|null, model: string|null}
 * @phpstan-type Relation array{declaration: AllowedRelation, target: self, cardinality: string}
 * @phpstan-type Path list<array{field: string, value: string|int|float|bool|null}>
 * @phpstan-type Sort list<array{field: string, direction: 'asc'|'desc'}>
 *
 * @phpstan-import-type Column from ModelMetadata
 */
final class Table
{
    use HasDisplayColumns;

    /** @var array<string, AllowedFilter> */
    public array $filters = [];

    /** @var array<string, Relation> */
    public array $relations = [];

    /** @var list<string> */
    public array $sorts = [];

    /** @var array<string, Group> */
    public array $groups = [];

    /** @var Sort */
    public array $defaultOrdering = [];

    /** @var Sort */
    public array $defaultGrouping = [];

    public int $increment = 50;

    public ?int $depth = null;

    private ?Authenticatable $actor = null;

    private function __construct(public readonly ZeroQuery $base) {}

    /** @param class-string<Model> $class */
    public static function for(string $class): self
    {
        foreach (resolve(ModelRegistry::class)->all() as $model) {
            if ($model->class === $class) {
                ScopeCompiler::globals($model);

                return new self(new ZeroQuery($model, $model->registry));
            }
        }

        throw new InvalidArgumentException('Table model is not registered with Zero: '.$class.'.');
    }

    public function visibleTo(?Authenticatable $actor): self
    {
        if ($this->base->model->policyVisibility) {
            $this->base->visibleTo($actor);
        }

        $this->actor = $actor;

        return $this;
    }

    /** @param list<AllowedFilter> $filters */
    public function allowedFilters(array $filters): self
    {
        foreach ($filters as $filter) {
            $column = $this->scalar($filter->field);
            $matches = match ($filter->kind) {
                'text' => $column['type'] === 'string' && ! $column['date'],
                'number' => $column['type'] === 'number' && ! $column['date'],
                'date' => $column['date'],
                default => ! $column['date'],
            };
            if (! $matches || isset($this->filters[$filter->name]) || isset($this->relations[$filter->name]) || (array_intersect($filter->operations, ['isNull', 'isNotNull']) !== [] && ! $column['nullable'])) {
                throw new InvalidArgumentException('Invalid or duplicate table filter '.$filter->name.'.');
            }

            $this->filters[$filter->name] = clone $filter;
        }

        return $this;
    }

    /** @param list<AllowedRelation> $relations */
    public function allowedRelations(array $relations): self
    {
        $this->declareRelations($relations, 0);

        return $this;
    }

    /** @return array<string, array<string, mixed>> */
    public function relationCapabilities(): array
    {
        $result = [];
        foreach ($this->relations as $name => $relation) {
            $definition = $relation['declaration'];
            $target = $relation['target'];
            $model = $target->base->model;
            $junction = $this->base->model->junctionLookups[$name] ?? null;
            $result[$name] = ['model' => $model->name, 'table' => $model->table, 'key' => $model->key,
                ...($definition->lookup !== null && $junction !== null ? ['junction' => $junction->capability()] : []),
                'keyColumn' => $model->columns[$model->key], 'cardinality' => $relation['cardinality'], 'label' => $definition->label,
                'operators' => $definition->effectiveOperators(),
                'filters' => (object) array_map(static fn (AllowedFilter $filter): array => ['field' => $filter->field, 'kind' => $filter->kind,
                    'operators' => $filter->operations, 'column' => $model->columns[$filter->field]], $target->filters),
                'relations' => (object) $target->relationCapabilities(), 'choices' => $definition->choices, 'lookup' => $definition->lookup];
        }

        return $result;
    }

    /** @param list<string> $fields */
    public function allowedSorts(array $fields): self
    {
        foreach ($fields as $field) {
            $this->scalar($field);
        }

        $this->sorts = array_values(array_unique($fields));

        return $this;
    }

    /** @param list<AllowedGroup> $groups */
    public function allowedGroups(array $groups): self
    {
        foreach ($groups as $group) {
            $field = $group->name;
            $target = null;
            if ($group->relation) {
                $model = $this->base->model;
                if (! method_exists($model->class, $group->name)) {
                    throw new InvalidArgumentException('Unknown table relation '.$group->name.'.');
                }

                $relation = (new $model->class)->{$group->name}();
                $path = $model->registry->relation($model, $group->name)['path'];
                if (! $relation instanceof BelongsTo || count($path) !== 1 || count($path[0]['source']) !== 1 || count($path[0]['dest']) !== 1 || ($model->relationConstraints[$group->name]['wheres'] ?? []) !== []) {
                    throw new InvalidArgumentException('Table grouping supports unconstrained single-key belongsTo relations only.');
                }

                $field = $path[0]['source'][0];
                $target = $model->registry->all()[$model->relations[$group->name]['model']];
                if ($path[0]['dest'][0] !== $target->key || $group->labelField === null || ! isset($target->columns[$group->labelField]) || $target->columns[$group->labelField]['type'] !== 'string') {
                    throw new InvalidArgumentException('Table relation requires an exposed text label and primary-key target.');
                }
            } elseif ($group->labelField !== null) {
                throw new InvalidArgumentException('Field groups cannot declare a relation label.');
            }

            if ($this->scalar($field)['date'] || isset($this->groups[$group->name])) {
                throw new InvalidArgumentException('Invalid or duplicate table group '.$group->name.'.');
            }

            $this->groups[$group->name] = ['field' => $field, 'relation' => $group->relation ? $group->name : null, 'label' => $group->labelField, 'model' => $target?->name];
        }

        return $this;
    }

    public function defaultSort(string $field, string $direction = 'asc'): self
    {
        $this->defaultOrdering = $this->ordering([['field' => $field, 'direction' => $direction]]);

        return $this;
    }

    /** @param Sort $rules */
    public function defaultGroupBy(array $rules): self
    {
        $this->defaultGrouping = $this->grouping($rules);

        return $this;
    }

    public function maxGroupDepth(int $depth): self
    {
        if ($depth < 0 || $depth > 3 || count($this->defaultGrouping) > $depth) {
            throw new InvalidArgumentException('Table group depth must be between zero and three and admit the default grouping.');
        }

        $this->depth = $depth;

        return $this;
    }

    public function pageSize(int $size): self
    {
        if ($size < 1 || $size >= QueryLimits::liveWindow()) {
            throw new InvalidArgumentException('Invalid table page size.');
        }

        $this->increment = $size;

        return $this;
    }

    public function where(string $field, mixed $operator, mixed $value): self
    {
        $this->base->where($field, $operator, $value);

        return $this;
    }

    /** @return Sort */
    public function grouping(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > ($this->depth ?? min(3, count($this->groups)))) {
            Input::fail('groupBy', 'Invalid group depth.');
        }

        $rules = [];
        foreach ($input as $item) {
            $item = Input::object($item, 'groupBy');
            Input::keys($item, ['field', 'direction'], 'groupBy');
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? null;
            if (! is_string($field) || ! isset($this->groups[$field]) || in_array($field, array_column($rules, 'field'), true) || ! in_array($direction, ['asc', 'desc'], true)) {
                Input::fail('groupBy', 'Unknown field, direction or repeated group dimension.');
            }

            $rules[] = ['field' => $field, 'direction' => $direction];
        }

        return $rules;
    }

    /** @param Sort $groupBy
     * @return Path
     */
    public function path(mixed $input, array $groupBy, bool $leaf = false): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > count($groupBy) || ($leaf && count($input) !== count($groupBy))) {
            Input::fail('path', 'Invalid group path length.');
        }

        $path = [];
        foreach ($input as $index => $part) {
            $part = Input::object($part, 'path');
            Input::keys($part, ['field', 'value'], 'path');
            $field = $groupBy[$index]['field'];
            if (($part['field'] ?? null) !== $field || ! array_key_exists('value', $part)) {
                Input::fail('path', 'Path is not a prefix of the grouping.');
            }

            $value = Input::value($part['value'], $this->base->model->columns[$this->groups[$field]['field']], 'path.value');
            if (! is_scalar($value) && $value !== null) {
                Input::fail('path.value', 'Group keys must be scalar.');
            }

            $path[] = ['field' => $field, 'value' => $value];
        }

        return $path;
    }

    /** @return Sort */
    public function ordering(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > count($this->sorts)) {
            Input::fail('sort', 'Invalid ordering.');
        }

        $sort = [];
        foreach ($input as $item) {
            $item = Input::object($item, 'sort');
            Input::keys($item, ['field', 'direction'], 'sort');
            $field = $item['field'] ?? null;
            $direction = $item['direction'] ?? null;
            if (! is_string($field) || ! in_array($field, $this->sorts, true) || in_array($field, array_column($sort, 'field'), true) || ! in_array($direction, ['asc', 'desc'], true)) {
                Input::fail('sort', 'Unknown field, direction or repeated ordering.');
            }

            $sort[] = ['field' => $field, 'direction' => $direction];
        }

        return $sort;
    }

    /** A complete, unbounded row declaration; the transport adds the live window.
     * @param  Path  $path
     * @param  Sort  $sort
     */
    public function rows(mixed $filter = null, array $path = [], array $sort = []): ZeroQuery
    {
        $query = $this->membership($filter, $path);
        foreach ($this->relations as $name => $relation) {
            $lookup = $relation['declaration']->lookup;
            if ($lookup !== null && isset($query->model->junctionLookups[$name])) {
                $query->withJunctionLookup($name, $lookup['limit'], $this->actor);
            } elseif ($lookup !== null) {
                $query->with($name, function (ZeroQuery $related) use ($lookup, $relation): void {
                    if ($related->model->policyVisibility) {
                        $related->visibleTo($this->actor);
                    }

                    $related->orderBy($related->model->key)->limit($relation['cardinality'] === 'one' ? 1 : $lookup['limit'] + 1);
                });
            }
        }

        foreach ($sort as $item) {
            $query->orderBy($item['field'], $item['direction']);
        }

        if (! in_array($query->model->key, array_column($sort, 'field'), true)) {
            $query->orderBy($query->model->key, 'asc');
        }

        return $query;
    }

    /** SQL membership derives from the same scoped row declaration, not a second filter parser.
     * @param  Path  $path
     */
    public function sql(ResolvedContext $context, mixed $filter = null, array $path = []): Builder
    {
        $model = $this->base->model;
        $query = (new $model->class)->newQueryWithoutScopes();
        if ($model->policyVisibility) {
            if (! $this->actor instanceof Authenticatable || Binding::id($this->actor->getAuthIdentifier()) !== $context->userId()) {
                throw new AuthorizationException('Table counts require the authenticated visibility actor.');
            }

            (new EloquentVisibility)->apply($query, $this->actor);
        }

        $sql = $query->getQuery();
        $ast = Input::object(ContextReference::resolve($this->membership($filter, $path)->ast(), $context), 'table');
        if (array_diff(array_keys($ast), ['table', 'where', 'orderBy', '__visibility']) !== []) {
            throw new LogicException('Table counts require unbounded, membership-only scopes.');
        }

        if (isset($ast['where'])) {
            Predicate::sql($sql, Input::object($ast['where'], 'condition'), $model, new RelationSnapshot($model->registry, $this->actor, $context));
        }

        return $sql;
    }

    /** @param list<AllowedRelation> $relations */
    private function declareRelations(array $relations, int $depth): void
    {
        $model = $this->base->model;
        foreach ($relations as $declaration) {
            $name = $declaration->name;
            if (isset($this->relations[$name]) || isset($this->filters[$name]) || str_starts_with($name, 'zsubq_')) {
                throw new InvalidArgumentException('Invalid or duplicate table relation '.$name.'.');
            }

            $edge = $model->registry->relation($model, $name);
            $nextDepth = $depth + count($edge['path']);
            if ($nextDepth > 7) {
                throw new InvalidArgumentException('Table relationship declarations exceed the maximum query depth.');
            }

            $related = $model->registry->get($edge['model']);
            $target = (new self(new ZeroQuery($related, $model->registry)))->allowedFilters($declaration->filters);
            $this->assertRelationMembership($name, $target->base);
            $target->declareRelations($declaration->relations, $nextDepth);
            $choices = $declaration->choices;
            $key = $target->scalar($related->key);
            if ($key['date'] || ! in_array($key['type'], ['string', 'number'], true)) {
                throw new InvalidArgumentException('Table relations require string or numeric keys.');
            }

            if ($choices !== null && ($choices['value'] !== $related->key || $target->scalar($choices['label'])['type'] !== 'string' || $related->columns[$choices['label']]['date'])) {
                throw new InvalidArgumentException('Table choices require the related primary key and an exposed text label.');
            }

            if ($declaration->lookup !== null) {
                $target->scalar($declaration->lookup['field']);
                if ($depth !== 0) {
                    throw new InvalidArgumentException('Bounded table lookup columns require a direct relationship.');
                }

                if (count($edge['path']) === 2) {
                    JunctionLookup::declare($model, $name);
                }
            }

            $this->relations[$name] = ['declaration' => clone $declaration, 'target' => $target, 'cardinality' => $edge['cardinality']];
        }
    }

    /** Check the same scopes a filtered row will see, without binding a generation-time actor. */
    private function assertRelationMembership(string $name, ZeroQuery $target): void
    {
        $scope = clone $target;
        $scope->withoutGlobalScopes(VisibleToCurrentUser::names(new $target->model->class));

        $this->base->model->applyRelationConstraints($name, $scope);
        $ast = $scope->ast();
        if (array_diff(array_keys($ast), ['table', 'where']) !== []) {
            throw new LogicException('Table relations require unbounded membership-only scopes.');
        }

        if (isset($ast['where'])) {
            Predicate::sql((new $target->model->class)->newQueryWithoutScopes()->getQuery(), Input::object($ast['where'], 'scope'), $target->model);
        }
    }

    /** @param Path $path */
    private function membership(mixed $filter, array $path): ZeroQuery
    {
        $query = clone $this->base;
        $condition = (new Predicate($query, $this->filters, $this->relations, $this->actor))->parse($filter);
        if ($condition instanceof Condition) {
            $query->addCondition($condition);
        }

        foreach ($path as $part) {
            $query->where($this->groups[$part['field']]['field'], $part['value']);
        }

        return $query;
    }

    /** @return Column */
    private function scalar(string $field): array
    {
        $column = $this->base->model->columns[$field] ?? null;
        if ($column === null || $column['type'] === 'json' || in_array($column['cast'] ?? null, ['array', 'object'], true)) {
            throw new InvalidArgumentException('Table field must be an exposed scalar: '.$field.'.');
        }

        return $column;
    }
}
