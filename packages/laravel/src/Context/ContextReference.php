<?php

declare(strict_types=1);

namespace ZeroForLaravel\Context;

use BackedEnum;
use ZeroForLaravel\ResolvedContext;

/** An application-owned enum case is a symbolic operand, never transport input. */
final readonly class ContextReference
{
    public function __construct(public string $field) {}

    public static function normalize(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value instanceof (ContextDefinition::configured()->enum) ? new self((string) $value->value) : $value->value;
        }

        return is_array($value) ? array_map(self::normalize(...), $value) : $value;
    }

    public static function resolve(mixed $value, ResolvedContext $context): mixed
    {
        if ($value instanceof self) {
            return $context->get($value->field);
        }

        return is_array($value) ? array_map(fn (mixed $item): mixed => self::resolve($item, $context), $value) : $value;
    }
}
