<?php

declare(strict_types=1);

namespace ZeroForLaravel\Formbraid;

use Formbraid\Laravel\OptionInput;

/** A deferred literal-text LIKE pattern, not PHP string interpolation. */
final readonly class OptionPattern
{
    public function __construct(public OptionInput $input) {}

    public static function contains(string $value): string
    {
        return '%'.strtr($value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']).'%';
    }
}
