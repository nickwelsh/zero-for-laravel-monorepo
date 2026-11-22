<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\QueryLimits;

/** A resolved path in this descriptor, never a relation name supplied by a query caller. */
final readonly class TableRelationPath
{
    /** A saved membership rule can contain 100 IDs even when listings use a smaller live window. */
    public const int MAX_SELECTED_IDS = 100;

    /** @param array{value: string, label: string, list?: string, resolve?: string}|null $choices */
    private function __construct(public string $path, public ModelMetadata $parent, public string $relation, public ModelMetadata $target, public ?array $choices) {}

    /** @return array<string, self> */
    public static function all(TableDefinition $definition): array
    {
        return self::paths($definition->table, '');
    }

    public static function pageSize(): int
    {
        return min(25, QueryLimits::liveWindow() - 1);
    }

    /** @param list<string> $bindings */
    public function queryName(TableDefinition $table, string $operation, array $bindings): string
    {
        $limits = $operation === 'list' ? ['cursor', self::pageSize(), QueryLimits::liveWindow()] : [self::MAX_SELECTED_IDS];

        return 'q_'.hash('sha256', serialize(['table-picker-v1', $table->contract, $this->path, $operation, $bindings, $limits]));
    }

    /** @return array<string, self> */
    private static function paths(Table $table, string $prefix): array
    {
        $paths = [];
        foreach ($table->relations as $name => $relation) {
            $target = $relation['target']->base->model;
            $choices = $relation['declaration']->choices;
            if ($choices !== null) {
                $label = $target->columns[$choices['label']];
                $key = $target->columns[$target->key];
                if ($label['type'] !== 'string' || $label['nullable'] || $label['date'] || $label['enum'] !== null || ($label['cast'] ?? 'string') !== 'string') {
                    throw new LogicException('Table choices require a non-null searchable text label, not a date, enum or converted value.');
                }

                $validKey = match ($key['type']) {
                    'string' => ($key['cast'] ?? 'string') === 'string',
                    'number' => in_array($key['cast'] ?? 'integer', ['integer', 'float'], true),
                    default => false,
                };
                if (! $validKey || $key['date'] || $key['nullable'] || $key['enum'] !== null) {
                    throw new LogicException('Table choice IDs must decode to their original string or number.');
                }
            }

            $path = $prefix.$name;
            $paths[$path] = new self($path, $table->base->model, $name, $target, $choices);
            $paths += self::paths($relation['target'], $path.'.');
        }

        return $paths;
    }
}
