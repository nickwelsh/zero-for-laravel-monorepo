<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Str;
use ZeroForLaravel\Generation\QueryExpression;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Support\Input;

/** A paired native/SQL contract compiled without invoking application definitions. */
final readonly class TableDefinition
{
    public function __construct(public string $name, public Table $table, public QueryExpression $expression, public string $contract, public bool $visible) {}

    public function bind(?Authenticatable $actor): Table
    {
        if ($this->visible) {
            $this->table->visibleTo($actor);
        }

        return $this->table;
    }

    public function queryName(): string
    {
        return 'q_'.$this->contract;
    }

    /** @return array<string, mixed> */
    public function capabilities(): array
    {
        $table = $this->table;

        return ['version' => 1, 'name' => $this->name, 'contract' => $this->contract,
            'model' => $table->base->model->name, 'key' => [$table->base->model->key],
            'pageSize' => $table->increment, 'maxGroupDepth' => $table->depth ?? min(3, count($table->groups)),
            'filters' => (object) array_map(fn (AllowedFilter $filter): array => ['field' => $filter->field, 'kind' => $filter->kind,
                'operators' => $filter->operations, 'column' => $table->base->model->columns[$filter->field]], $table->filters),
            'sorts' => $table->sorts, 'groups' => (object) $table->groups,
            'columns' => $table->base->model->columns,
            'defaults' => ['sort' => $table->defaultOrdering, 'groupBy' => $table->defaultGrouping],
            'metadataURL' => '/'.trim(config()->string('zero.route_prefix'), '/').'/tables/'.rawurlencode($this->name).'/metadata'];
    }

    public function rows(mixed $arguments): ZeroQuery
    {
        $args = Input::object($arguments, 'args');
        Input::keys($args, ['filter', 'sort', 'groupBy', 'path', '__window', '__generation', '__row', '__refresh'], 'args');
        if (array_key_exists('__refresh', $args) && (! array_key_exists('__row', $args) || ! is_string($args['__refresh']) || ! Str::isUuid($args['__refresh']))) {
            Input::fail('refresh', 'Identity refreshes require UUIDs and a row key.');
        }

        $groups = $this->table->grouping($args['groupBy'] ?? $this->table->defaultGrouping);
        $path = $this->table->path($args['path'] ?? [], $groups, leaf: true);
        $sort = $this->table->ordering($args['sort'] ?? $this->table->defaultOrdering);
        $window = $args['__window'] ?? $this->table->increment + 1;
        if (! is_int($window) || $window < 1 || $window > 2147483647) {
            Input::fail('window', 'Invalid live table window.');
        }

        if (array_key_exists('__row', $args)) {
            $identity = $args['__row'];
            if (! is_array($identity) || ! array_is_list($identity) || count($identity) !== 1 || ($args['filter'] ?? null) !== null || $groups !== [] || $sort !== [] || $window !== 1) {
                Input::fail('row', 'Identity reads require one key and no view filters, grouping or sorting.');
            }

            $model = $this->table->base->model;
            $value = Input::value($identity[0], $model->columns[$model->key], 'row.key');

            // Keep the table's mandatory scopes and live visibility predicate.
            return $this->table->rows()->where($model->key, '=', $value)->limit(1);
        }

        return $this->table->rows($args['filter'] ?? null, $path, $sort)->limit($window);
    }
}
