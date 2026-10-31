<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

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

        $queryActions = [];
        foreach ($queries as $query) {
            [$controller, $method] = explode('@', $query->action, 2);
            $segments = explode('\\', $controller);
            foreach ([...$segments, $method] as $segment) {
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment)) {
                    throw new LogicException('Unsupported TypeScript controller identifier: '.$segment);
                }
            }

            $path = implode('/', $segments).'.ts';
            if (! isset($queryActions[$path])) {
                $import = (count($segments) === 1 ? './' : str_repeat('../', count($segments) - 1)).$base.'.queries';
                $controllers[$path] ??= [self::HEADER];
                $controllers[$path][] = 'import {actions as liveQueries} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
            }

            $action = json_encode($query->action.'.'.$query->prop, JSON_THROW_ON_ERROR);
            $queryActions[$path][$method][] = '['.json_encode($query->prop, JSON_THROW_ON_ERROR).']: Object.assign((input: Parameters<typeof liveQueries['.$action.']>[0]) => liveQueries['.$action.'](input).request(), {live: liveQueries['.$action.']})';
        }

        foreach ($queryActions as $path => $methods) {
            $index = 0;
            foreach ($methods as $method => $properties) {
                $controllers[$path][] = 'const query'.$index.' = {'.implode(', ', $properties).'};';
                $controllers[$path][] = 'export {query'.$index.' as '.$method.'};';
                $index++;
            }
        }

        return array_map(fn (array $lines): string => implode("\n", $lines)."\n", $controllers);
    }
}
