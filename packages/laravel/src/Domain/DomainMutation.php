<?php

declare(strict_types=1);

namespace ZeroForLaravel\Domain;

use ZeroForLaravel\ZeroContext;

/** Explicit extension point. The FormRequest owns authoritative authorization and validation. */
abstract class DomainMutation
{
    /**
     * A small wire contract, not a PHP method exposure mechanism.
     *
     * @return array<string, array{type: 'string'|'number'|'boolean', optional?: bool, nullable?: bool, array?: bool, maxItems?: int}>
     */
    abstract public static function input(): array;

    /** @return class-string<\Illuminate\Foundation\Http\FormRequest> */
    abstract public static function request(): string;

    /** @param array<string, mixed> $input */
    abstract public function handle(array $input, ZeroContext $context): void;
}
