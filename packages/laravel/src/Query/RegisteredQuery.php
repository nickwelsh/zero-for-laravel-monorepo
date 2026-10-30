<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use LogicException;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\ZeroContext;

/** An application-generated registration, never supplied by the browser. */
final readonly class RegisteredQuery
{
    /**
     * @param  array<string, string>  $parameters
     * @param  Closure(array<string, mixed>): Query  $factory
     * @param  array<string,\ZeroForLaravel\Generation\Mutations\ValueContract>  $inputContracts
     */
    public function __construct(
        public string $action,
        public string $component,
        public string $prop,
        public array $parameters,
        private Closure $factory,
        public array $inputContracts = [],
    ) {}

    /** @return array<string, mixed> */
    public function compile(mixed $arguments, ZeroContext $context, QueryCompiler $compiler): array
    {
        return $compiler->compileDeclaration($this->declaration($arguments), $context);
    }

    public function declaration(mixed $arguments): Query
    {
        $args = Input::object($arguments, 'args');
        $window = $args['__window'] ?? null;
        $cursor = $args['__cursor'] ?? null;
        unset($args['__route'], $args['__routeArgs'], $args['__window'], $args['__cursor']);
        Input::keys($args, array_keys($this->parameters), 'args');
        foreach ($this->parameters as $name => $type) {
            QueryInput::validate($type, $args[$name] ?? null, $name);
            if (str_starts_with($type, 'request:')) {
                $args[$name] = Input::object($args[$name], 'args.'.$name);
            }
        }

        $query = ($this->factory)($args);
        if ($cursor !== null) {
            $cursor = Input::object($cursor, 'cursor');
            Input::keys($cursor, $query->cursorFields(), 'cursor');
            if (! $query->isPaginated() || count($cursor) !== count($query->cursorFields())) {
                Input::fail('cursor', 'Cursor must contain every registered ordering field.');
            }

            $query = $query->start($cursor);
        }

        if ($window !== null) {
            if ($query->pageSize() === null || ! is_int($window) || $window < 1 || $window > 2147483647 || ($query->isPaginated() && $window !== $query->pageSize() + 1)) {
                Input::fail('window', 'Invalid live query window.');
            }

            $query = $query->limit($window);
        }

        return $query;
    }

    /** @return array<string, mixed> */
    public function invoke(mixed $arguments, ZeroContext $identity, QueryCompiler $compiler): array
    {
        $wire = Input::object($arguments, 'args');
        $args = $wire;
        unset($args['__route'], $args['__routeArgs'], $args['__window'], $args['__cursor']);
        $input = [];
        $routeParameters = $args;
        foreach ($this->parameters as $name => $type) {
            QueryInput::validate($type, $args[$name] ?? null, $name);
            if (str_starts_with($type, 'request:')) {
                $args[$name] = Input::object($args[$name], 'args.'.$name);
                ksort($args[$name]);
                $input = $args[$name];
                unset($routeParameters[$name]);
            }
        }

        $routeArguments = isset($wire['__routeArgs']) ? Input::object($wire['__routeArgs'], 'route') : array_map(fn (mixed $value): mixed => is_bool($value) ? (int) $value : $value, $routeParameters);
        if (isset($wire['__route'])) {
            if (! is_string($wire['__route'])) {
                Input::fail('route', 'Invalid route identity.');
            }

            $routeArguments['__route'] = $wire['__route'];
        }

        return resolve(ControllerRouteDispatcher::class)->run($this->action, $routeArguments, $input, function (Request $request, Route $route) use ($args, $wire, $identity, $compiler): array {
            foreach (QueryInput::arguments($this->parameters, $request, $route, $this->inputContracts) as $name => $bound) {
                if ($bound !== $args[$name]) {
                    Input::fail('args', 'Query arguments differ from the validated controller inputs.');
                }
            }

            $resolver = resolve(config()->string('zero.context_resolver'));
            if (! is_callable($resolver)) {
                throw new LogicException('Zero context resolver must be callable.');
            }

            $context = $resolver($request);
            if (! $context instanceof ZeroContext || $context->userId() !== $identity->userId()) {
                throw new AuthorizationException('Controller identity differs from the authenticated Zero identity.');
            }

            return $compiler->compileDeclaration($this->declaration($wire), $context);
        });
    }
}
