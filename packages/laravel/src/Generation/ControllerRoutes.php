<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Routing\Route as BaseRoute;
use Laravel\Ranger\Collectors\Routes;
use Laravel\Ranger\Components\Route;

/** Discover route identities without recursively analyzing arbitrary HTTP response bodies. */
final class ControllerRoutes extends Routes
{
    protected function filterRoute(BaseRoute $route): bool
    {
        return ! in_array($route->getName(), ['zero.query', 'zero.mutate'], true)
            && parent::filterRoute($route);
    }

    protected function mapToRoute(BaseRoute $route): Route
    {
        // Our checked controller compiler owns response/query analysis. Ranger's
        // general response collector would traverse mailers, auth and vendor code
        // even for actions that have no Zero declaration.
        return (new Route($route, collect(), $this->forcedScheme, $this->forcedRoot))
            ->setBasePaths(...$this->basePaths)->setAppPaths(...$this->appPaths);
    }
}
