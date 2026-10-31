<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Support\Str;
use LogicException;
use ZeroForLaravel\Generation\Mutations\MutationProgram;

/** Human-facing imports; protocol registrations remain compiler-owned. */
final class PublicApiGenerator
{
    public const string HEADER = '// Generated Zero public API. Do not edit.';

    /**
     * @param  list<MutationProgram>  $mutations
     * @param  list<ControllerQuery>  $queries
     * @return array<string, string> Paths relative to the generated schema directory.
     */
    public function generate(array $mutations, array $queries, string $base): array
    {
        $controllers = [];
        foreach ($mutations as $index => $mutation) {
            [$controller, $method] = explode('@', $mutation->action, 2);
            $segments = explode('\\', $controller);
            foreach ([...$segments, $method] as $segment) {
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment)) {
                    throw new LogicException('Unsupported TypeScript controller identifier: '.$segment);
                }
            }

            $path = implode('/', $segments).'.ts';
            $import = (count($segments) === 1 ? './' : str_repeat('../', count($segments) - 1)).$base.'.mutations';
            $controllers[$path] ??= [self::HEADER, "import type {ControllerMutationAction} from '@nickwelsh/zero-for-laravel';"];
            $controllers[$path][] = 'import {inputSchema'.$index.', serverResultSchema'.$index.'} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
            $controllers[$path][] = 'import type {Input'.$index.', ServerResult'.$index.'} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
            $controllers[$path][] = 'const action'.$index.' = {action: '.json_encode($mutation->action, JSON_THROW_ON_ERROR).', inputSchema: inputSchema'.$index.', serverResultSchema: serverResultSchema'.$index.'} as const satisfies ControllerMutationAction<Input'.$index.', ServerResult'.$index.'>;';
            $controllers[$path][] = 'export {action'.$index.' as '.$method.'};';
            $controllers[$path][] = 'export type {Input'.$index.' as '.ucfirst($method).'Input, ServerResult'.$index.' as '.ucfirst($method).'Result} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
        }

        $outputs = array_map(fn (array $lines): string => implode("\n", $lines)."\n", $controllers);
        $pages = [];
        foreach ($queries as $query) {
            $name = implode('.', array_map(Str::studly(...), explode('/', str_replace('::', '/', $query->component))));
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $name)) {
                throw new LogicException('Unsupported TypeScript page name: '.$query->component);
            }

            if (isset($pages[$name]) && $pages[$name]['component'] !== $query->component) {
                throw new LogicException('Inertia page names collide after normalization: '.$query->component.' and '.$pages[$name]['component']);
            }

            $pages[$name]['component'] = $query->component;
            $pages[$name]['actions'][$query->action] = true;
        }

        ksort($pages);
        $lines = [self::HEADER, 'import type {Pages as Contracts} from '.json_encode('./'.$base.'.queries', JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';', 'declare global {', '  namespace Inertia {', '    namespace Pages {'];
        foreach ($pages as $name => $page) {
            $segments = explode('.', $name);
            $type = array_pop($segments);
            foreach ($segments as $segment) {
                $lines[] = 'namespace '.$segment.' {';
            }

            $lines[] = 'type '.$type.' = '.implode(' | ', array_map(fn (string $action): string => 'Contracts['.json_encode($action, JSON_THROW_ON_ERROR).']', array_keys($page['actions']))).';';
            foreach ($segments as $_) {
                $lines[] = '}';
            }
        }

        $outputs['pages.d.ts'] = implode("\n", [...$lines, '    }', '  }', '}', '']);

        return $outputs;
    }
}
