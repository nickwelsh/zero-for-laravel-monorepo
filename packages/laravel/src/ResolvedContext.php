<?php

declare(strict_types=1);

namespace ZeroForLaravel;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use LogicException;

/** Identity is transport-authenticated and independent of application field names. */
final readonly class ResolvedContext
{
    /** @param array<string, mixed> $values */
    public function __construct(public array $values, private ?string $identity = null) {}

    public static function authenticatedId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();
        if ($id !== null && ! is_string($id) && ! is_int($id)) {
            throw new LogicException('Authenticated Zero identity must be a string or integer.');
        }

        return $id === null ? null : (string) $id;
    }

    public function get(string $field): string|int|float|bool|null
    {
        if (! array_key_exists($field, $this->values)) {
            throw new AuthorizationException('Missing trusted context: '.$field.'.');
        }

        $value = $this->values[$field];
        if ($value !== null && (! is_scalar($value) || is_float($value) && ! is_finite($value))) {
            throw new AuthorizationException('Invalid trusted context: '.$field.'.');
        }

        return $value;
    }

    public function userId(): ?string
    {
        return $this->identity;
    }
}
