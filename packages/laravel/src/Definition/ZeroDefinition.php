<?php

declare(strict_types=1);

namespace ZeroForLaravel\Definition;

use Closure;
use LogicException;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Query\ZQLBuilder;
use ZeroForLaravel\Search\ScoutSearch;
use ZeroForLaravel\ZeroContext;

final class ZeroDefinition
{
    public bool $public = false;

    public ?ScoutSearch $search = null;

    /** @var list<array{field: string, op: string, value: array{context: string}|array{literal: string|int|float|bool|null}}> */
    public array $authorization = [];

    /** @var list<string> */
    public array $authorizationRelations = [];

    /** @var array<string, string> */
    public array $serverFill = [];

    /** @var list<Closure(ZQLBuilder, ZeroContext): mixed> */
    public array $serverScopes = [];

    /** @var list<string> */
    public array $scopeRelations = [];

    public static function make(): self
    {
        return new self;
    }

    public function search(ScoutSearch $search): self
    {
        $this->search = $search;

        return $this;
    }

    public function publicReads(): self
    {
        $this->public = true;

        return $this;
    }

    public function authorize(string $field, string $operator, ContextField|string|int|float|bool|null $value): self
    {
        $this->authorization[] = ['field' => $field, 'op' => $operator, 'value' => $value instanceof ContextField ? ['context' => $value->value] : ['literal' => $value]];

        return $this;
    }

    public function authorizeRelation(string $relation): self
    {
        $this->authorizationRelations[] = $relation;

        return $this;
    }

    /** @param Closure(ZQLBuilder, ZeroContext): mixed $scope
     * @param  list<string>  $relations  Relationships needed by the scope.
     */
    public function enforceServerScope(Closure $scope, array $relations = []): self
    {
        $this->serverScopes[] = $scope;
        $this->relations(...$relations);

        return $this;
    }

    /** Declare schema dependencies used by controller queries and visibility. */
    public function relations(string ...$relations): self
    {
        $this->scopeRelations = array_values(array_unique([...$this->scopeRelations, ...$relations]));

        return $this;
    }

    public function fillFromContext(string $field, ContextField|string $source): self
    {
        if (isset($this->serverFill[$field])) {
            throw new LogicException("Conflicting context fill for {$field}.");
        }

        $this->serverFill[$field] = $source instanceof ContextField ? $source->value : $source;

        return $this;
    }
}
