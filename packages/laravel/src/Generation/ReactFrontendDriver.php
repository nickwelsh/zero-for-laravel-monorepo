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
        $prefix = rtrim('/'.trim(config()->string('zero.route_prefix'), '/'), '/');
        $query = json_encode($prefix.'/query', JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $mutate = json_encode($prefix.'/mutate', JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return <<<TS
        import type { ProviderOptions } from '@nickwelsh/zero-for-laravel/react';

        const origin = import.meta.env.VITE_APP_URL;
        export const options = {
            // Relative cache URLs follow the page origin; SSR uses the configured app URL.
            cacheURL: new URL(import.meta.env.VITE_ZERO_CACHE_URL ?? 'http://localhost:4848', globalThis.location?.href ?? origin).href,
            ...(origin && {
                queryURL: new URL({$query}, origin).href,
                mutateURL: new URL({$mutate}, origin).href,
            }),
        } satisfies ProviderOptions;

        TS;
    }
}
