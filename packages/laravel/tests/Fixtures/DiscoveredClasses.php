<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use ZeroForLaravel\Support\ApplicationClasses;

final class DiscoveredClasses extends ApplicationClasses
{
    /** @param list<class-string> $classes */
    public function __construct(public array $classes) {}

    public function all(): array
    {
        return $this->classes;
    }
}
