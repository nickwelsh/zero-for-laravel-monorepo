<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

interface FrontendDriver
{
    /** @return array<string, string> Filenames relative to the generated schema. */
    public function files(string $base, bool $custom): array;

    /** Initial contents of the application-owned zero.config.ts, if needed. */
    public function configuration(): ?string;
}
