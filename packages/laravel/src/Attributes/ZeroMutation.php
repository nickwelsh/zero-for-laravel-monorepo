<?php

declare(strict_types=1);

namespace ZeroForLaravel\Attributes;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ZeroMutation
{
    public function __construct(
        public string $optimism = 'generated',
        public bool $conceal = false,
        public ?string $serverResult = null,
        public ?string $clientResult = null,
    ) {
        if (! in_array($optimism, ['generated', 'custom', 'disabled'], true)) {
            throw new InvalidArgumentException('Unknown mutation optimism strategy.');
        }
    }
}
