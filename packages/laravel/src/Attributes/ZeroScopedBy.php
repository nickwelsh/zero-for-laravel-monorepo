<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;
use ZeroForLaravel\Scopes\ZeroGlobalScope;

#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class ZeroScopedBy
{
    /** @param list<class-string<ZeroGlobalScope>> $scopes */
    public function __construct(public array $scopes) {}
}
