<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use JsonException;
use LogicException;
use stdClass;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use ZeroForLaravel\Domain\OperationException;
use ZeroForLaravel\Generation\Mutations\MutationProgram;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\ZeroContext;

/**
 * Receives only trusted, generated registrations, never an AST from the browser.
 *
 * @phpstan-import-type Contract from MutationProgram
 */
final readonly class ControllerMutationRegistry
{
    /** @param array<string,array{contract:Contract,guards:list<Closure(array<string,mixed>):void>,result?:Closure(mixed):mixed}> $registrations */
    public function __construct(private ModelRegistry $models, private ControllerRouteDispatcher $routes, private array $registrations = []) {}

    public static function json(mixed $value, int $depth = 0, bool $declaredObjects = false): void
    {
        if ($depth > 64 || (is_object($value) && (! $declaredObjects || ! $value instanceof stdClass)) || is_resource($value) || (is_float($value) && ! is_finite($value))) {
            throw new OperationException('invalid_result', 'Mutation result must be explicit JSON data.');
        }

        if (is_array($value) || $value instanceof stdClass) {
            foreach (is_array($value) ? $value : get_object_vars($value) as $item) {
                self::json($item, $depth + 1, $declaredObjects);
            }
        }

        try {
            json_encode($value, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OperationException('invalid_result', 'Mutation result must be explicit JSON data.');
        }
    }

    public function has(string $name): bool
    {
        return isset($this->registrations[$name]);
    }

    /**
     * Returns the native persisted outcome envelope; caller owns the protocol transaction.
     *
     * @return array{data?:mixed}
     */
    public function execute(string $name, mixed $arguments, ZeroContext $context): array
    {
        $entry = $this->registrations[$name] ?? throw new OperationException('unknown_mutation', 'Unsupported mutation version.');
        $contract = $entry['contract'];
        try {
            $wire = Input::object($arguments, 'mutation');
            Input::keys($wire, ['version', 'input', 'route', 'prepared'], 'mutation');
            if (($wire['version'] ?? null) !== $contract['version']) {
                throw new OperationException('unknown_mutation', 'Unsupported mutation version.');
            }

            $input = Input::object($wire['input'] ?? null, 'input');
            $routeArguments = Input::object($wire['route'] ?? null, 'route');
            $prepared = Input::object($wire['prepared'] ?? null, 'prepared');
            Input::keys($prepared, ['ids', 'timestamp'], 'prepared');
            $ids = Input::object($prepared['ids'] ?? null, 'prepared.ids');
            Input::keys($ids, array_keys($contract['sites']), 'prepared.ids');
            $validatedIds = [];
            foreach ($contract['sites'] as $site => $generator) {
                $pattern = $generator === 'ulid' ? '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D' : '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/Di';
                if (! is_string($ids[$site] ?? null) || ! preg_match($pattern, $ids[$site])) {
                    Input::fail('prepared.ids.'.$site, 'Invalid prepared identity.');
                }

                $validatedIds[$site] = $ids[$site];
            }

            if (count(array_unique($validatedIds)) !== count($validatedIds)) {
                Input::fail('prepared.ids', 'Creation identities must be distinct.');
            }

            if (! is_int($prepared['timestamp'] ?? null) || $prepared['timestamp'] < 0 || $prepared['timestamp'] > 8640000000000000) {
                Input::fail('prepared.timestamp', 'Invalid stable timestamp.');
            }

            $prepared = ['ids' => $validatedIds, 'timestamp' => $prepared['timestamp']];
            $result = $this->routes->run($contract['action'], $routeArguments, $input, function (Request $request, Route $route) use ($contract, $entry, $prepared, $context): mixed {
                if ($this->models->connection()->transactionLevel() === 0) {
                    throw new LogicException('Controller mutation must run within the native transaction.');
                }

                $mutation = new Mutation($this->models, $prepared, $context, fn (Model $locked): Model => $this->rebind($route, $locked));
                if ($contract['optimism'] !== 'generated') {
                    $previous = app()->bound(Mutation::class) ? resolve(Mutation::class) : null;
                    app()->instance(Mutation::class, $mutation);
                    try {
                        return $route->run();
                    } finally {
                        app()->forgetInstance(Mutation::class);
                        if ($previous !== null) {
                            app()->instance(Mutation::class, $previous);
                        }
                    }
                }

                $locals = [];
                foreach ($contract['parameters'] as $name => $parameter) {
                    $type = $parameter['type'] ?? throw new LogicException('Missing compiled parameter type.');
                    $locals[$name] = match ($parameter['kind']) {
                        'request' => resolve($type), // Laravel resolves preparation, authorize(), and validation with the scoped request.
                        'context' => $mutation,
                        'helper' => resolve($type), // Shared methods are lowered; explicit server boundaries still receive real DI objects.
                        default => $route->parameter($name),
                    };
                    if ($parameter['kind'] === 'model' && ! $locals[$name] instanceof $type) {
                        throw new LogicException('Missing actual route model binding for '.$name);
                    }
                }

                return (new ControllerMutationRuntime($mutation, $entry['guards'] ?? []))->run($contract['operations'], $locals);
            });
            if (! $contract['returns']) {
                if ($result !== null) {
                    throw new OperationException('invalid_result', 'Void mutation returned data.');
                }

                return [];
            }

            $valid = match ($contract['returnType']) {
                'mixed' => true, 'null' => $result === null, 'array' => is_array($result),
                'string' => is_string($result), 'int' => is_int($result),
                'float' => is_float($result) || is_int($result), 'bool' => is_bool($result),
                default => false,
            };
            if (! $valid) {
                throw new OperationException('invalid_result', 'Mutation result does not match its declared PHP type.');
            }

            if (isset($entry['result'])) {
                $result = ($entry['result'])($result);
            }

            self::json($result, declaredObjects: isset($entry['result']));

            return ['data' => $result];
        } catch (AuthorizationException|ModelNotFoundException|ValidationException $error) {
            if (! $contract['conceal']) {
                throw $error;
            }

            throw new OperationException('unavailable', 'The requested operation is unavailable.');
        } catch (HttpExceptionInterface $error) {
            if (! $contract['conceal'] || ! in_array($error->getStatusCode(), [403, 404, 422], true)) {
                throw $error;
            }

            throw new OperationException('unavailable', 'The requested operation is unavailable.');
        }
    }

    /** Recheck real custom/parent-scoped binding after acquiring the row lock. */
    private function rebind(Route $route, Model $locked): Model
    {
        foreach ($route->parameters() as $name => $bound) {
            if (! $bound instanceof Model || ! $bound->is($locked)) {
                continue;
            }

            $freshRoute = clone $route;
            foreach ($route->originalParameters() as $key => $value) {
                if (! is_string($value) && ! is_object($value) && $value !== null) {
                    throw new LogicException('Unsupported original route parameter.');
                }

                $freshRoute->setParameter($key, $value);
            }

            resolve('router')->substituteBindings($freshRoute);
            resolve('router')->substituteImplicitBindings($freshRoute);
            $fresh = $freshRoute->parameter($name);
            if (! $fresh instanceof Model || ! $fresh->is($locked)) {
                throw (new ModelNotFoundException)->setModel($locked::class);
            }

            return $fresh;
        }

        return $locked;
    }
}
