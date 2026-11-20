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
        public ?string $navigateAfter = null,
    ) {
        if (! in_array($optimism, ['generated', 'custom', 'disabled'], true)) {
            throw new InvalidArgumentException('Unknown mutation optimism strategy.');
        }

        if (! in_array($navigateAfter, [null, 'local', 'server'], true)) {
            throw new InvalidArgumentException('navigateAfter must be local or server.');
        }
    }

    /** Preserve deployed mutation fingerprints when navigation is not configured.
     * @return array<string,mixed>
     */
    public function __serialize(): array
    {
        return ['optimism' => $this->optimism, 'conceal' => $this->conceal, 'serverResult' => $this->serverResult, 'clientResult' => $this->clientResult,
            ...($this->navigateAfter === null ? [] : ['navigateAfter' => $this->navigateAfter])];
    }
}
