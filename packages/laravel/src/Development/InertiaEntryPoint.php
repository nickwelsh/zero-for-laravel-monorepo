<?php

declare(strict_types=1);

namespace ZeroForLaravel\Development;

/** Only insert a resolver in the React starter's direct, literal app declaration. */
final class InertiaEntryPoint
{
    public static function withResolver(string $source): ?string
    {
        $newline = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $source = str_replace("\r\n", "\n", $source);
        $call = 'createInertiaApp({';
        if (substr_count($source, $call) !== 1
            || ! preg_match('/^import \{ createInertiaApp \} from [\'"]@inertiajs\/react[\'"];$/m', $source)
            || ! preg_match('/^(?:void )?createInertiaApp\(\{\n/m', $source)
            || preg_match('/\b(?:resolve|pages|livePages)\b|[{,]\s*\.\.\.|(?:^|,)\s*\[/m', $source)) {
            return null;
        }

        $resolver = <<<'TS'

            resolve: async (name, page) => {
                const pages = import.meta.glob<{ default: import('react').ComponentType }>('./pages/**/*.tsx');
                const module = await pages[`./pages/${name}.tsx`]();
                return livePages.wrap(module.default, page);
            },
        TS;
        $source = str_replace($call, $call.$resolver, $source);

        return str_replace("\n", $newline, "import { livePages } from './zero/inertia';\n".$source);
    }
}
