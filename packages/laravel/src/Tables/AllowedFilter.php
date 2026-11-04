<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use InvalidArgumentException;

/** Public aliases are capabilities, not arbitrary query column names. */
final class AllowedFilter
{
    /** @var list<string> */
    public array $operations;

    private function __construct(public readonly string $name, public readonly string $field, public readonly string $kind)
    {
        $this->operations = $kind === 'exact' ? ['equals', 'in'] : ['equals'];
    }

    public static function exact(string $name, ?string $field = null): self
    {
        return new self($name, $field ?? $name, 'exact');
    }

    public static function text(string $name, ?string $field = null): self
    {
        return new self($name, $field ?? $name, 'text');
    }

    public static function partial(string $name, ?string $field = null): self
    {
        return self::text($name, $field)->operators(['contains']);
    }

    public static function number(string $name, ?string $field = null): self
    {
        return new self($name, $field ?? $name, 'number');
    }

    public static function date(string $name, ?string $field = null): self
    {
        return new self($name, $field ?? $name, 'date');
    }

    /** @param list<string> $operators */
    public function operators(array $operators): self
    {
        $allowed = match ($this->kind) {
            'exact' => ['equals', 'in'],
            'text' => ['equals', 'notEquals', 'contains', 'startsWith', 'endsWith'],
            default => ['equals', 'notEquals', 'in', 'gt', 'gte', 'lt', 'lte'],
        };
        if ($operators === [] || array_diff($operators, $allowed) !== []) {
            throw new InvalidArgumentException('Unsupported operators for filter '.$this->name.'.');
        }

        $this->operations = array_values(array_unique($operators));

        return $this;
    }

    public function nullable(): self
    {
        $this->operations = array_values(array_unique([...$this->operations, 'isNull', 'isNotNull']));

        return $this;
    }
}
