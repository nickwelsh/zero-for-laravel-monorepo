<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Closure;
use Laravel\Ranger\Components\Route;
use LogicException;
use ReflectionMethod;

/** Ranger owns registered route discovery; the checked compiler owns live declarations. */
final readonly class ControllerDiscovery
{
    public function __construct(private ControllerQueryCompiler $compiler) {}

    /** @return array<string, array{query: ControllerQuery, routes: list<Route>}> */
    public function queries(): array
    {
        $queries = [];
        $actions = [];
        $this->walk(function (Route $route) use (&$queries, &$actions): void {
            if (! $route->hasController()) {
                return;
            }

            $action = $route->controller().'@'.$route->method();
            $definitions = $actions[$action] ??= $this->compiler->compile($route->controller(), $route->method());
            foreach ($definitions as $query) {
                $name = $query->name();
                $queries[$name] ??= ['query' => $query, 'routes' => []];
                $queries[$name]['routes'][] = $route;
            }
        });
        ksort($queries);

        return $queries;
    }

    /** @return list<Mutations\MutationProgram> */
    public function mutations(): array
    {
        $programs = [];
        $this->walk(function (Route $route) use (&$programs): void {
            if (! $route->hasController()) {
                return;
            }

            $action = $route->controller().'@'.$route->method();
            if (isset($programs[$action]) || (new ReflectionMethod($route->controller(), $route->method()))->getAttributes(\ZeroForLaravel\Attributes\ZeroMutation::class) === []) {
                return;
            }

            $programs[$action] = resolve(Mutations\ControllerMutationCompiler::class)->compile(ltrim($route->controller(), '\\'), $route->method());
        });
        ksort($programs);

        return array_values($programs);
    }

    /** @param Closure(Route): void $callback */
    private function walk(Closure $callback): void
    {
        if (app()->routesAreCached()) {
            throw new LogicException('Clear the route cache before generating Zero controller contracts.');
        }

        $routes = resolve(ControllerRoutes::class)->setBasePaths(base_path())->setAppPaths(app_path());
        foreach ($routes->collect() as $route) {
            $callback($route);
        }
    }
}
