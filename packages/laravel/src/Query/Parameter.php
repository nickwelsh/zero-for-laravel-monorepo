<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use InvalidArgumentException;

/** A native static parameter, not a browser-supplied authorization value. */
final readonly class Parameter
{
    /** @param string|list<string> $field */
    public function __construct(public string $anchor, public string|array $field)
    {
        if (! in_array($anchor, ['authData', 'preMutationRow'], true)) {
            throw new InvalidArgumentException('Unknown native parameter anchor.');
        }

        if (is_array($field) && (! array_is_list($field) || $field === [] || array_filter($field, fn (mixed $part): bool => ! is_string($part)) !== [])) {
            throw new InvalidArgumentException('Parameter paths must contain strings.');
        }
    }

    /** @return array{type: string, anchor: string, field: string|list<string>} */
    public function toArray(): array
    {
        return ['type' => 'static', 'anchor' => $this->anchor, 'field' => $this->field];
    }
}
