<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** An explicit edge in the table's filter tree, not the model's entire relation graph. */
final class AllowedRelation
{
    private const array MEMBERSHIP = ['has', 'any', 'all'];

    /** @var list<AllowedFilter> */
    public array $filters = [];

    /** @var list<self> */
    public array $relations = [];

    /** @var array{value: string, label: string, list?: string, resolve?: string}|null */
    public ?array $choices = null;

    /** @var array{field: string, limit: int}|null */
    public ?array $lookup = null;

    public string $label;

    /** @var list<string>|null Null derives the defaults; an empty list forbids filtering. */
    private ?array $operations = null;

    private function __construct(public readonly string $name)
    {
        $this->label = Str::headline($name);
    }

    public static function named(string $name): self
    {
        return new self($name);
    }

    /** @param list<AllowedFilter> $filters */
    public function allowedFilters(array $filters): self
    {
        $this->filters = $filters;

        return $this;
    }

    /** @param list<self> $relations */
    public function allowedRelations(array $relations): self
    {
        $this->relations = $relations;

        return $this;
    }

    /** @param list<string> $operators */
    public function operators(array $operators): self
    {
        if (! array_is_list($operators)) {
            throw new InvalidArgumentException('Relation operators must be a list.');
        }

        foreach ($operators as $operator) {
            if (! in_array($operator, ['some', ...self::MEMBERSHIP], true)) {
                throw new InvalidArgumentException('Unsupported operator for relation '.$this->name.'.');
            }
        }

        $this->operations = array_values(array_unique($operators));

        return $this;
    }

    /** @return list<string> */
    public function effectiveOperators(): array
    {
        if ($this->operations !== null) {
            return $this->operations;
        }

        if ($this->filters !== [] || $this->relations !== []) {
            return ['some', ...self::MEMBERSHIP];
        }

        return $this->lookup !== null && $this->choices === null ? [] : self::MEMBERSHIP;
    }

    public function choices(string $value, string $label, ?string $list = null, ?string $resolve = null): self
    {
        $this->choices = ['value' => $value, 'label' => $label, ...($list !== null ? ['list' => $list] : []), ...($resolve !== null ? ['resolve' => $resolve] : [])];

        return $this;
    }

    public function label(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function lookup(string $field, int $limit = 1): self
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('A table lookup preview requires a limit between 1 and 100.');
        }

        $this->lookup = ['field' => $field, 'limit' => $limit];

        return $this;
    }
}
