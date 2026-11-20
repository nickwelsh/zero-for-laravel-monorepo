<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Router;
use LogicException;
use ZeroForLaravel\Generation\Mutations\MutationContracts;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Generation\Mutations\ValueContract;

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
        $exports = [];
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
            $reference = '{action: '.json_encode($mutation->action, JSON_THROW_ON_ERROR).', inputSchema: inputSchema'.$index.', serverResultSchema: serverResultSchema'.$index.'} as const satisfies ControllerMutationAction<Input'.$index.', ServerResult'.$index.'>';
            $route = $this->routeFields($mutation);
            if ($route !== null) {
                $controllers[$path][1] = "import {controllerForm, type ControllerMutationAction} from '@nickwelsh/zero-for-laravel';";
                $fields = (new MutationContracts)->input($mutation->parameters)->fields;
                $reference = 'controllerForm('.$reference.', '.json_encode($route, JSON_THROW_ON_ERROR).' as const, '.json_encode((object) array_map($this->field(...), $fields), JSON_THROW_ON_ERROR).')';
            }

            $controllers[$path][] = 'const action'.$index.' = '.$reference.';';
            $exports[$path][$method] = 'action'.$index;
            $controllers[$path][] = 'export {action'.$index.' as '.$method.'};';
            $controllers[$path][] = 'export type {Input'.$index.' as '.ucfirst($method).'Input, ServerResult'.$index.' as '.ucfirst($method).'Result} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
        }

        $queryActions = [];
        foreach ($queries as $query) {
            if ($query->optionSource !== null) {
                continue;
            }

            [$controller, $method] = explode('@', $query->action, 2);
            $segments = explode('\\', $controller);
            foreach ([...$segments, $method] as $segment) {
                if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $segment)) {
                    throw new LogicException('Unsupported TypeScript controller identifier: '.$segment);
                }
            }

            $path = implode('/', $segments).'.ts';
            if (! isset($queryActions[$path])) {
                $queryActions[$path] = [];
                $import = (count($segments) === 1 ? './' : str_repeat('../', count($segments) - 1)).$base.'.queries';
                $controllers[$path] ??= [self::HEADER];
                $controllers[$path][] = 'import {actions as liveQueries} from '.json_encode($import, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).';';
            }

            $action = json_encode($query->action.'.'.$query->prop, JSON_THROW_ON_ERROR);
            $callable = 'Object.assign((input: Parameters<typeof liveQueries['.$action.']>[0]) => liveQueries['.$action.'](input).request(), {live: liveQueries['.$action.']})';
            if ($query->standalone) {
                $controllers[$path][] = 'export const '.$method.' = '.$callable.';';
                $exports[$path][$method] = $method;
            } else {
                $queryActions[$path][$method][] = '['.json_encode($query->prop, JSON_THROW_ON_ERROR).']: '.$callable;
            }
        }

        foreach ($queryActions as $path => $methods) {
            $index = 0;
            foreach ($methods as $method => $properties) {
                $controllers[$path][] = 'const query'.$index.' = {'.implode(', ', $properties).'};';
                $controllers[$path][] = 'export {query'.$index.' as '.$method.'};';
                $exports[$path][$method] = 'query'.$index;
                $index++;
            }
        }

        foreach ($exports as $path => $members) {
            $controllers[$path][] = 'export default {'.implode(', ', array_map(static fn (string $name, string $local): string => $name.': '.$local, array_keys($members), $members)).'};';
        }

        return array_map(fn (array $lines): string => implode("\n", $lines)."\n", $controllers);
    }

    /** @return list<array{string,string}>|null */
    private function routeFields(MutationProgram $mutation): ?array
    {
        $routes = array_values(array_filter(resolve(Router::class)->getRoutes()->getRoutes(), static fn (\Illuminate\Routing\Route $route): bool => ltrim($route->getActionName(), '\\') === $mutation->action));
        if (count($routes) !== 1) {
            return null;
        }

        $route = $routes[0];
        $fields = [];
        foreach ($route->parameterNames() as $name) {
            if (! is_string($name)) {
                throw new LogicException('Expected a named route parameter.');
            }

            $type = $mutation->parameters[$name]['type'] ?? '';
            $fields[] = [$name, $route->bindingFieldFor($name) ?? (is_subclass_of($type, Model::class) ? (new $type)->getRouteKeyName() : 'id')];
        }

        return $fields;
    }

    /** @return array<string,mixed> */
    private function field(ValueContract $field): array
    {
        return ['kind' => $field->kind, ...($field->nullable ? ['nullable' => true] : []),
            ...(in_array('required', $field->rules, true) ? ['required' => true] : []),
            ...($field->fields !== [] ? ['fields' => array_map($this->field(...), $field->fields)] : []),
            ...($field->kind === 'list' ? ['item' => $this->field($field->members[0])] : [])];
    }
}
