<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\Facade;
use LogicException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response;
use ZeroForLaravel\Support\Input;

/** Re-enter the action's real middleware and binding boundary, never its HTTP body implicitly. */
final class ControllerRouteDispatcher
{
    public static function identity(Route $route): string
    {
        return hash('sha256', json_encode([$route->getActionName(), $route->methods(), $route->getDomain(), $route->uri(), $route->getName()], JSON_THROW_ON_ERROR));
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $routeArguments
     * @param  array<string, mixed>  $input
     * @param  Closure(Request, Route): T  $terminal
     * @return T
     */
    public function run(string $action, array $routeArguments, array $input, Closure $terminal): mixed
    {
        $router = resolve(Router::class);
        $parent = resolve(Request::class);
        $selector = $routeArguments['__route'] ?? null;
        unset($routeArguments['__route']);
        $matches = [];
        foreach ($router->getRoutes()->getRoutes() as $candidate) {
            $candidateAction = $candidate->getActionName();
            if ($candidate->getControllerClass() !== null && ! str_contains($candidateAction, '@')) {
                $candidateAction .= '@__invoke';
            }

            if (ltrim($candidateAction, '\\') !== ltrim($action, '\\') || ($selector !== null && self::identity($candidate) !== $selector)) {
                continue;
            }

            foreach (array_keys($routeArguments) as $parameter) {
                if (! in_array($parameter, $candidate->parameterNames(), true)) {
                    continue 2;
                }
            }

            $matches[] = $candidate;
        }

        if (count($matches) !== 1) {
            Input::fail('route', 'Missing or ambiguous controller route. Regenerate the application contracts.');
        }

        $route = clone $matches[0];
        $route->flushController();
        foreach ($routeArguments as $value) {
            if (! is_string($value) && ! is_int($value)) {
                Input::fail('route', 'Route values must be strings or integers.');
            }
        }

        $urls = resolve(UrlGenerator::class);
        $url = $urls->toRoute($route, $routeArguments, true);
        $cookies = $parent->attributes->get('zero.transport.cookies', $parent->cookies->all());
        if (! is_array($cookies)) {
            throw new LogicException('Invalid internal transport cookie snapshot.');
        }

        $request = Request::create($url, $route->methods()[0], $input, $cookies, [], $parent->server->all());
        $request->headers->replace($parent->headers->all());
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('Content-Type', 'application/json');
        $request->setJson(new InputBag($input));
        $request->attributes->set('zero.controller.dispatch', true);
        $request->setUserResolver($parent->getUserResolver());
        if ($parent->hasSession()) {
            $request->setLaravelSession($parent->session());
        }

        if (! $route->matches($request)) {
            Input::fail('route', 'Route arguments do not satisfy the registered route.');
        }

        $route->bind($request);
        $scoped = new ControllerRouter($router);
        $scoped->scope($route, $request);

        $previousRoute = app()->bound(Route::class) ? resolve(Route::class) : null;
        app()->instance('router', $scoped);
        app()->instance('request', $request);
        app()->instance(Route::class, $route);
        Facade::clearResolvedInstance('router');
        Facade::clearResolvedInstance('request');
        $urls->setRequest($request);
        try {
            event(new RouteMatched($route, $request));
            $middleware = array_values(array_filter($scoped->gatherRouteMiddleware($route), static function (mixed $middleware): bool {
                if (! is_string($middleware)) {
                    return true;
                }

                $class = explode(':', $middleware, 2)[0];

                // The enclosing native endpoint authenticates zero-cache separately.
                // Browser CSRF tokens are not carried by queued/replayed Zero pushes.
                return ! is_a($class, VerifyCsrfToken::class, true)
                    && ! is_a($class, PreventRequestForgery::class, true);
            }));
            $results = [];
            $response = (new Pipeline(app()))->send($request)->through($middleware)->then(function (Request $request) use ($route, $terminal, &$results): Response {
                $results[] = $terminal($request, $route);

                return new Response('', 204);
            });
            if (count($results) !== 1 || ! $response instanceof Response || ! $response->isSuccessful()) {
                throw new AuthorizationException('The controller route rejected this operation.');
            }

            return $results[0];
        } finally {
            app()->instance('router', $router);
            app()->instance('request', $parent);
            if ($previousRoute !== null) {
                app()->instance(Route::class, $previousRoute);
            } else {
                app()->forgetInstance(Route::class);
            }

            Facade::clearResolvedInstance('router');
            Facade::clearResolvedInstance('request');
            $urls->setRequest($parent);
        }
    }
}
