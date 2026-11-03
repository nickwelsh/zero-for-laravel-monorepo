<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PhpParser\ParserFactory;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Generation\ControllerQueryCompiler;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** An application-generated registration, never supplied by the browser. */
final readonly class RegisteredQuery
{
    /**
     * @param  array<string, string>  $parameters
     * @param  Closure(array<string, mixed>): ZeroQuery  $factory
     * @param  array<string,ValueContract>  $inputContracts
     * @param  list<array{parameter: string, class: class-string<Model>, ability: string, fingerprint: string}>  $guards
     */
    public function __construct(
        public string $action,
        public string $component,
        public string $prop,
        public array $parameters,
        private Closure $factory,
        public array $inputContracts = [],
        public array $guards = [],
        public ?string $contract = null,
    ) {}

    public function assertCurrent(): void
    {
        if ($this->contract === null) {
            return;
        }

        [$class, $method] = explode('@', $this->action, 2);
        $compiler = new ControllerQueryCompiler((new ParserFactory)->createForHostVersion(), resolve(ModelRegistry::class));
        foreach ($compiler->compile($class, $method) as $query) {
            if ($query->prop === $this->prop && $query->name() === $this->contract) {
                return;
            }
        }

        throw new LogicException('Stale Zero policy/schema registration. Regenerate and deploy paired contracts.');
    }

    /** @return array<string, mixed> */
    public function compile(mixed $arguments, ResolvedContext $context, QueryCompiler $compiler): array
    {
        return $compiler->compileDeclaration($this->declaration($arguments), $context);
    }

    public function declaration(mixed $arguments): ZeroQuery
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
    public function invoke(mixed $arguments, ResolvedContext $identity, QueryCompiler $compiler): array
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

            $context = ContextDefinition::configured()->resolve($request);
            if ($context->userId() !== $identity->userId()) {
                throw new AuthorizationException('Controller identity differs from the authenticated Zero identity.');
            }

            $this->assertCurrent();
            $guards = [];
            foreach ($this->guards as $guard) {
                $resource = $route->parameter($guard['parameter']);
                if (! $resource instanceof Model || ! $resource instanceof $guard['class']) {
                    throw new LogicException('Captured Gate resource differs from the bound route model.');
                }

                Gate::forUser($request->user())->authorize($guard['ability'], $resource);
                $guards[] = ['class' => $guard['class'], 'id' => Binding::id($resource->getKey()), 'ability' => $guard['ability']];
            }

            return $compiler->compileDeclaration($this->declaration($wire), $context, $guards, serving: true);
        });
    }
}
