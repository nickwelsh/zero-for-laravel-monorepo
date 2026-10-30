<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

/** Validator syntax is selected at generation time, not in the query runtime. */
interface SchemaDriver
{
    public function imports(): string;

    /** @param array<string, string> $parameters
     * @param  array<string, string>|null  $cursor
     */
    public function object(array $parameters, ?array $cursor = null, bool $public = false): string;

    /** @param array<string, string> $parameters */
    public function encode(array $parameters, string $input): string;

    public function inputType(string $schema): string;

    public function parse(string $schema, string $input): string;
}
