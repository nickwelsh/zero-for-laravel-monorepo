<?php

declare(strict_types=1);

namespace ZeroForLaravel\Inertia;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Inertia\AlwaysProp;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;
use LogicException;
use ZeroForLaravel\Generation\GuardedAccess;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Query\QueryInput;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Query\ZeroQuery;

final readonly class LiveProps implements ProvidesInertiaProperties
{
    /**
     * @param  array<array-key, mixed>  $props
     * @param  array<array-key, mixed>  $shared
     */
    public function __construct(private array $props, private array $shared = []) {}

    public function toInertiaProperties(RenderContext $context): iterable
    {
        $props = [];
        foreach ($this->props as $key => $value) {
            if (is_int($key) && $value instanceof ProvidesInertiaProperties) {
                foreach ($value->toInertiaProperties($context) as $providedKey => $provided) {
                    $props[$providedKey] = $provided;
                }
            } elseif (is_string($key)) {
                $props[$key] = $value;
            } else {
                throw new LogicException('Inertia props must have string keys or be property providers.');
            }
        }

        if (array_key_exists('__zero', $props)) {
            throw new LogicException('The __zero Inertia metadata prop is reserved.');
        }

        $route = $context->request->route();
        $boundProps = [];
        if ($route instanceof Route) {
            foreach ($props as $key => $value) {
                if (! $value instanceof Model || ! in_array($value, $route->parameters(), true)) {
                    continue;
                }

                $registry = resolve(ModelRegistry::class);
                foreach ($registry->all() as $model) {
                    if ($value::class === $model->class) {
                        $boundProps[$key] = $value;
                        $props[$key] = (new ZeroQuery($model, $registry))->find($value->getKey());
                        break;
                    }
                }
            }
        }

        $partial = $context->request->header('X-Inertia-Partial-Component') === $context->component;
        $live = array_filter($props, fn (mixed $value): bool => $value instanceof ZeroQuery);
        foreach ($live as $key => $value) {
            if (array_key_exists($key, $this->shared)) {
                throw new LogicException("Shared prop {$key} collides with a live query.");
            }
        }

        if ($live !== []) {
            foreach ($this->shared as $value) {
                if ($value instanceof ProvidesInertiaProperties) {
                    throw new LogicException('Shared property providers need an explicit live-prop collision contract.');
                }
            }
        }

        if ($live !== [] && ! $route instanceof Route) {
            throw new LogicException('Live Inertia props require a registered controller route.');
        }

        $registrations = $live !== [] && $route instanceof Route ? resolve(RegisteredQueries::class)->forAction($route->getActionName()) : [];
        // A selected ordinary prop explicitly replaces any earlier live binding.
        // Omitted props have no entry and retain their binding on partial visits.
        $bindings = $partial ? array_fill_keys(array_keys(array_diff_key($props, $live)), null) : [];
        foreach ($live as $prop => $declaration) {
            $matches = array_filter($registrations, fn (RegisteredQuery $query): bool => $query->prop === $prop && $query->component === $context->component);
            if (count($matches) !== 1) {
                throw new LogicException("Missing or ambiguous live prop {$prop}. Run zero:generate.");
            }

            $name = array_key_first($matches);
            $registration = $matches[$name];
            $registration->assertCurrent();
            $access = null;
            foreach ($registration->guards as $guard) {
                if ($guard['parameter'] === null) {
                    $access = GuardedAccess::contract((new PolicyCompiler)->compileClass($guard['class'], $guard['ability']));
                    break;
                }

                if (isset($boundProps[$prop]) && $route->parameter($guard['parameter']) === $boundProps[$prop]) {
                    $declaration = $declaration->guardedBy($context->request->user() ?? throw new LogicException('A protected prop requires an authenticated actor.'), $guard['ability']);
                    $access = GuardedAccess::contract((new PolicyCompiler)->compile($guard['class'], $guard['ability']));
                    break;
                }
            }

            // These are the actual Laravel-bound values, in the same positional
            // order used by the controller dispatcher, never parsed from a URL.
            $args = QueryInput::arguments($registration->parameters, $context->request, $route, $registration->inputContracts);
            $generated = $registration->declaration($args);
            if (json_encode([$generated->ast(), $generated->format()], JSON_THROW_ON_ERROR) !== json_encode([$declaration->ast(), $declaration->format()], JSON_THROW_ON_ERROR)) {
                throw new LogicException("Live prop {$prop} differs from its generated contract. Run zero:generate.");
            }

            $args['__route'] = ControllerRouteDispatcher::identity($route);
            $args['__routeArgs'] = (object) $route->originalParameters();
            foreach ($registration->parameters as $parameter => $type) {
                if (str_starts_with($type, 'request:')) {
                    $args[$parameter] = (object) $args[$parameter];
                }
            }

            $bindings[$prop] = ['query' => $name, 'args' => (object) $args];
            if ($access !== null) {
                $bindings[$prop]['access'] = $access;
            }

            unset($props[$prop]);
        }

        if ($partial) {
            $only = array_filter(explode(',', $context->request->header('X-Inertia-Partial-Data', '')));
            $except = array_filter(explode(',', $context->request->header('X-Inertia-Partial-Except', '')));
            $bindings = array_filter($bindings, fn (string|int $prop): bool => ($bindings[$prop]['access']['scope'] ?? null) === 'page'
                || ($only === [] || in_array($prop, $only, true)) && ! in_array($prop, $except, true), ARRAY_FILTER_USE_KEY);
        }

        $metadata = ['version' => 1, 'mode' => $partial ? 'patch' : 'replace', 'bindings' => (object) $bindings];
        foreach ($bindings as $binding) {
            if (($binding['access']['scope'] ?? null) === 'page') {
                // Persistent Inertia layouts (outside ZeroProvider) receive transport
                // props directly. Only the approved page receives these ordinary props.
                $metadata['pageProps'] = (object) $props;
                $props = [];
                break;
            }
        }

        $props['__zero'] = new AlwaysProp($metadata);

        return $props;
    }
}
