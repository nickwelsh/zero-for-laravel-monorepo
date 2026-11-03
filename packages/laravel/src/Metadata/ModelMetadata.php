<?php

declare(strict_types=1);

namespace ZeroForLaravel\Metadata;

use Closure;
use ZeroForLaravel\Query\ZQLBuilder;
use ZeroForLaravel\Search\ScoutSearch;
use ZeroForLaravel\ZeroContext;

/**
 * @phpstan-type Column array{type: 'string'|'number'|'boolean'|'json', nullable: bool, date: bool, enum: list<string|int>|null, hasDefault: bool, default: mixed, dateFormat?: string, cast?: 'boolean'|'integer'|'float'|'string'|'array'|'object'}
 */
final class ModelMetadata
{
    /**
     * @var array<string, array{model: string, cardinality: string, path: list<array{source: list<string>, dest: list<string>, table: string}>}>
     */
    public array $relations = [];

    /**
     * @param  class-string<\Illuminate\Database\Eloquent\Model>  $class
     * @param  array<string, Column>  $columns
     * @param  list<array{field: string, op: string, value: array{context: string}|array{literal: mixed}}>  $authorization
     * @param  array<string, string>  $serverFill
     * @param  list<string>  $writable
     * @param  list<string>  $authorizationRelations
     * @param  list<Closure(ZQLBuilder, ZeroContext): mixed>  $serverScopes
     * @param  list<string>  $relationDependencies
     */
    public function __construct(
        public readonly string $class,
        public readonly string $name,
        public readonly string $table,
        public readonly string $key,
        public readonly array $columns,
        public readonly array $authorization,
        public readonly array $serverFill,
        public readonly array $writable,
        public readonly ?string $idGenerator,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        public readonly ?string $deletedAt,
        public readonly array $authorizationRelations = [],
        public array $serverScopes = [],
        public readonly array $relationDependencies = [],
        public readonly ?ScoutSearch $search = null,
        public readonly bool $policyVisibility = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            ...($this->search instanceof ScoutSearch ? ['search' => $this->search->metadata()] : []),
            'name' => $this->name, 'table' => $this->table, 'key' => $this->key,
            'columns' => $this->columns, 'authorization' => $this->authorization,
            ...($this->authorizationRelations === [] ? [] : ['authorizationRelations' => $this->authorizationRelations]),
            'serverFill' => (object) $this->serverFill, 'writable' => $this->writable,
            'idGenerator' => $this->idGenerator,
            'timestamps' => ['created' => $this->createdAt, 'updated' => $this->updatedAt, 'deleted' => $this->deletedAt],
            'relations' => (object) $this->relations,
        ];
    }
}
