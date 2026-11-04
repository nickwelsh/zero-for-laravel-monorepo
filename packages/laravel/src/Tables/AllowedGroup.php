<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

/** Group identity uses a scalar field; a relation supplies only its label. */
final class AllowedGroup
{
    public ?string $labelField = null;

    private function __construct(public readonly string $name, public readonly bool $relation) {}

    public static function field(string $field): self
    {
        return new self($field, false);
    }

    public static function belongsTo(string $relation): self
    {
        return new self($relation, true);
    }

    public function label(string $field): self
    {
        $this->labelField = $field;

        return $this;
    }
}
