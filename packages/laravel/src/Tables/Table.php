<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use LogicException;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\EloquentVisibility;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/**
 * Mutable, application-owned capabilities. Build a fresh definition per request.
 *
 * @phpstan-type Group array{field: string, relation: string|null, label: string|null, model: string|null}
 * @phpstan-type Path list<array{field: string, value: string|int|float|bool|null}>
 * @phpstan-type Sort list<array{field: string, direction: 'asc'|'desc'}>
 *
 * @phpstan-import-type Column from ModelMetadata
 */
final class Table
{
    /** @var array<string, AllowedFilter> */
    public array $filters = [];

    /** @var list<string> */
    public array $sorts = [];

    /** @var array<string, Group> */
    public array $groups = [];

    /** @var Sort */
    public array $defaultOrdering = [];

    /** @var list<string> */
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
                foreach ((new $class)->getGlobalScopes() as $scope) {
                    if (! $scope instanceof SoftDeletingScope && ! $scope instanceof VisibleToCurrentUser) {
                        throw new LogicException($class.': table counts require portable Zero scopes, not SQL-only global scopes.');
                    }
                }

                return new self(new ZeroQuery($model, $model->registry));
            }
        }

        throw new InvalidArgumentException('Table model is not registered with Zero: '.$class.'.');
    }

    public function visibleTo(?Authenticatable $actor): self
    {
        $this->base->visibleTo($actor);
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
            if (! $matches || isset($this->filters[$filter->name]) || (array_intersect($filter->operations, ['isNull', 'isNotNull']) !== [] && ! $column['nullable'])) {
                throw new InvalidArgumentException('Invalid or duplicate table filter '.$filter->name.'.');
            }

            $this->filters[$filter->name] = clone $filter;
        }

        return $this;
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

    /** @param list<string> $fields */
    public function defaultGroupBy(array $fields): self
    {
        $this->defaultGrouping = $this->grouping($fields);

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
        if ($size < 1 || $size >= 2147483647) {
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

    /** @return list<string> */
    public function grouping(mixed $input): array
    {
        if (! is_array($input) || ! array_is_list($input) || count($input) > ($this->depth ?? min(3, count($this->groups)))) {
            Input::fail('groupBy', 'Invalid group depth.');
        }

        $fields = [];
        foreach ($input as $field) {
            if (! is_string($field) || ! isset($this->groups[$field]) || in_array($field, $fields, true)) {
                Input::fail('groupBy', 'Unknown or repeated group dimension.');
            }

            $fields[] = $field;
        }

        return $fields;
    }

    /** @param list<string> $groupBy
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
            if (($part['field'] ?? null) !== $groupBy[$index] || ! array_key_exists('value', $part)) {
                Input::fail('path', 'Path is not a prefix of the grouping.');
            }

            $value = Input::value($part['value'], $this->base->model->columns[$this->groups[$groupBy[$index]]['field']], 'path.value');
            if (! is_scalar($value) && $value !== null) {
                Input::fail('path.value', 'Group keys must be scalar.');
            }

            $path[] = ['field' => $groupBy[$index], 'value' => $value];
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
        $query = clone $this->base;
        $condition = (new Predicate($query, $this->filters))->parse($filter);
        if ($condition instanceof Condition) {
            Predicate::zero($query, $condition);
        }

        foreach ($path as $part) {
            $query->where($this->groups[$part['field']]['field'], $part['value']);
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
        $ast = Input::object(ContextReference::resolve($this->rows($filter, $path)->ast(), $context), 'table');
        if (isset($ast['where'])) {
            Predicate::sql($sql, Input::object($ast['where'], 'condition'), $model);
        }

        return $sql;
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
