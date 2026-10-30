<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

/** Typed recursive expression tree, lowered to the native wire AST at the edge. */
final readonly class Condition
{
    /**
     * @param  array{op?: string, left?: array<string, mixed>, right?: array<string, mixed>, related?: array<string, mixed>, flip?: bool, scalar?: bool}  $fields
     * @param  list<self>  $conditions
     */
    public function __construct(public string $type, public array $fields = [], public array $conditions = []) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['type' => $this->type, ...$this->fields, ...(in_array($this->type, ['and', 'or'], true)
            ? ['conditions' => array_map(fn (self $condition): array => $condition->toArray(), $this->conditions)] : [])];
    }

    public function isEmptyGroup(string $type): bool
    {
        return $this->type === $type && $this->conditions === [];
    }
}
