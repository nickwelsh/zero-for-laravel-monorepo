<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

final readonly class ReactFrontendDriver implements FrontendDriver
{
    public function __construct(private TypeScriptGenerator $generator) {}

    public function files(string $base, bool $custom): array
    {
        return [$base.'.react.ts' => $this->generator->generateReact('./'.$base, './zero.config', './'.$base.'.mutations', $custom)];
    }

    public function configuration(): string
    {
        return "import type {ProviderOptions} from '@nickwelsh/zero-for-laravel/react';\n\n// Public connection options only; never put the cache API key here.\nexport const options = {cacheURL: 'http://localhost:4848'} satisfies ProviderOptions;\n";
    }
}
