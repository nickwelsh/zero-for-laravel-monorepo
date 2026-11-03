<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;
use BackedEnum;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ResolvesContext
{
    public function __construct(public BackedEnum $field) {}
}
