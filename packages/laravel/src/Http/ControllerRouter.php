<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/** A request-local router preserves Route facade semantics without mutating the outer dispatch. */
final class ControllerRouter extends Router
{
    public function __construct(private readonly Router $source)
    {
        parent::__construct(resolve('events'), app());
        $this->routes = $source->getRoutes();
        $this->middleware = $source->getMiddleware();
        $this->middlewareGroups = $source->getMiddlewareGroups();
        $this->middlewarePriority = $source->middlewarePriority;
    }

    public function scope(Route $route, Request $request): void
    {
        $this->current = $route;
        $this->currentRequest = $request;
        $route->setRouter($this)->setContainer(app());
        $request->setRouteResolver(fn (): Route => $route);
    }

    public function substituteBindings($route)
    {
        return $this->source->substituteBindings($route);
    }

    public function substituteImplicitBindings($route): void
    {
        $this->source->substituteImplicitBindings($route);
    }
}
