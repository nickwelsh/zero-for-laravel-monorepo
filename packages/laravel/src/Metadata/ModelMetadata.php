<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Search\ScoutSearch;

/**
 * @phpstan-type Column array{type: 'string'|'number'|'boolean'|'json', nullable: bool, date: bool, enum: list<string|int>|null, hasDefault: bool, default: mixed, dateFormat?: string, cast?: 'boolean'|'integer'|'float'|'string'|'array'|'object'}
 * @phpstan-type RelationWhere array{type: 'Basic', column: string, operator: string, value: mixed, boolean: 'and'|'or'}|array{type: 'Nested', wheres: list<array<string, mixed>>, boolean: 'and'|'or'}
 * @phpstan-type RelationConstraints array{wheres: list<RelationWhere>, orders: list<array{column: string, direction: 'asc'|'desc'}>}
 */
final class ModelMetadata
{
    /**
     * @var array<string, array{model: string, cardinality: string, path: list<array{source: list<string>, dest: list<string>, table: string}>}>
     */
    public array $relations = [];

    /** @var array<string, RelationConstraints> */
    public array $relationConstraints = [];

    public ModelRegistry $registry;

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  array<string, Column>  $columns
     * @param  list<string>  $writable
     */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $table,
        public readonly string $key,
        public readonly array $columns,
        public readonly array $writable,
        public readonly ?string $idGenerator,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $deletedAt,
        public readonly ?ScoutSearch $search = null,
    ) {}

    public static function column(string $column): string
    {
        return substr($column, (int) strrpos('.'.$column, '.'));
    }

    public function applyRelationConstraints(string $name, ZeroQuery $query): void
    {
        $constraints = $this->relationConstraints[$name] ?? ['wheres' => [], 'orders' => []];
        self::applyWheres($query, $constraints['wheres']);
        foreach ($constraints['orders'] as $order) {
            $query->orderBy(self::column($order['column']), $order['direction']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...($this->search instanceof ScoutSearch ? ['search' => $this->search->metadata()] : []),
            'name' => $this->name, 'table' => $this->table, 'key' => $this->key,
            'columns' => $this->columns, 'writable' => $this->writable,
            'scopeFingerprint' => \ZeroForLaravel\Scopes\ScopeRegistry::fingerprint($this),
            'creationDefaults' => (object) \ZeroForLaravel\Generation\ScopeCompiler::defaults($this),
            'idGenerator' => $this->idGenerator,
            'timestamps' => ['created' => $this->createdAt, 'updated' => $this->updatedAt, 'deleted' => $this->deletedAt],
            'relations' => (object) $this->relations,
            'relationConstraints' => (object) $this->relationConstraints,
        ];
    }

    /** @param list<RelationWhere> $wheres */
    private static function applyWheres(ZeroQuery $query, array $wheres): void
    {
        foreach ($wheres as $where) {
            $method = ($where['boolean'] ?? 'and') === 'or' ? 'orWhere' : 'where';
            if ($where['type'] === 'Nested') {
                $query->{$method}(fn (ZeroQuery $nested) => self::applyWheres($nested, ModelRegistry::relationWheres($where['wheres'], $nested->model, normalized: true)));
            } else {
                $query->{$method}(self::column($where['column']), $where['operator'], $where['value']);
            }
        }
    }
}
