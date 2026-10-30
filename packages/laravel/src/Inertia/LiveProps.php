<?php

declare(strict_types=1);

namespace ZeroForLaravel\Inertia;

use Illuminate\Routing\Route;
use Inertia\AlwaysProp;
use Inertia\ProvidesInertiaProperties;
use Inertia\RenderContext;
use LogicException;
use ZeroForLaravel\Query\Query;
use ZeroForLaravel\Query\RegisteredQueries;

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
        if ($route instanceof Route) {
            foreach ($props as $key => $value) {
                if (! $value instanceof \Illuminate\Database\Eloquent\Model || ! in_array($value, $route->parameters(), true)) {
                    continue;
                }

                $registry = resolve(\ZeroForLaravel\Metadata\ModelRegistry::class);
                foreach ($registry->all() as $model) {
                    if ($value::class === $model->class) {
                        $props[$key] = (new Query($model, $registry))->where($model->key, $value->getKey())->one();
                        break;
                    }
                }
            }
        }

        $partial = $context->request->header('X-Inertia-Partial-Component') === $context->component;
        $live = array_filter($props, fn (mixed $value): bool => $value instanceof Query);
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
            $matches = array_filter($registrations, fn (\ZeroForLaravel\Query\RegisteredQuery $query): bool => $query->prop === $prop && $query->component === $context->component);
            if (count($matches) !== 1) {
                throw new LogicException("Missing or ambiguous live prop {$prop}. Run zero:generate.");
            }

            $name = array_key_first($matches);
            $registration = $matches[$name];
            // These are the actual Laravel-bound values, in the same positional
            // order used by the controller dispatcher, never parsed from a URL.
            $args = \ZeroForLaravel\Query\QueryInput::arguments($registration->parameters, $context->request, $route, $registration->inputContracts);
            $generated = $registration->declaration($args);
            if (json_encode([$generated->ast(), $generated->format()], JSON_THROW_ON_ERROR) !== json_encode([$declaration->ast(), $declaration->format()], JSON_THROW_ON_ERROR)) {
                throw new LogicException("Live prop {$prop} differs from its generated contract. Run zero:generate.");
            }

            $args['__route'] = \ZeroForLaravel\Http\ControllerRouteDispatcher::identity($route);
            $args['__routeArgs'] = (object) $route->originalParameters();
            foreach ($registration->parameters as $parameter => $type) {
                if (str_starts_with($type, 'request:')) {
                    $args[$parameter] = (object) $args[$parameter];
                }
            }

            $bindings[$prop] = ['query' => $name, 'args' => (object) $args];
            unset($props[$prop]);
        }

        if ($partial) {
            $only = array_filter(explode(',', $context->request->header('X-Inertia-Partial-Data', '')));
            $except = array_filter(explode(',', $context->request->header('X-Inertia-Partial-Except', '')));
            $bindings = array_filter($bindings, fn (string|int $prop): bool => ($only === [] || in_array($prop, $only, true)) && ! in_array($prop, $except, true), ARRAY_FILTER_USE_KEY);
        }

        $props['__zero'] = new AlwaysProp(['version' => 1, 'mode' => $partial ? 'patch' : 'replace', 'bindings' => (object) $bindings]);

        return $props;
    }
}
