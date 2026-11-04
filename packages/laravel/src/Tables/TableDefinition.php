<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use ZeroForLaravel\Generation\QueryExpression;
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

    public function rows(mixed $arguments): \ZeroForLaravel\Query\ZeroQuery
    {
        $args = Input::object($arguments, 'args');
        Input::keys($args, ['filter', 'sort', 'groupBy', 'path', '__window', '__generation'], 'args');
        $groups = $this->table->grouping($args['groupBy'] ?? $this->table->defaultGrouping);
        $path = $this->table->path($args['path'] ?? [], $groups, leaf: true);
        $sort = $this->table->ordering($args['sort'] ?? $this->table->defaultOrdering);
        $window = $args['__window'] ?? $this->table->increment + 1;
        if (! is_int($window) || $window < 1 || $window > 2147483647) {
            Input::fail('window', 'Invalid live table window.');
        }

        return $this->table->rows($args['filter'] ?? null, $path, $sort)->limit($window);
    }
}
