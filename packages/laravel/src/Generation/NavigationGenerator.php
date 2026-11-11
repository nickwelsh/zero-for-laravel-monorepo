<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use ReflectionMethod;
use Symfony\Component\Routing\CompiledRoute;
use ZeroForLaravel\Http\ControllerRouteDispatcher;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Query\QueryInput;

/** Conservative early arguments; Laravel still validates and authorizes every query invocation. */
final class NavigationGenerator
{
    /** @param list<ControllerQuery> $queries */
    public function typescript(array $queries): string
    {
        $routes = [];
        foreach (resolve(Router::class)->getRoutes()->getRoutes() as $route) {
            if (! in_array('GET', $route->methods(), true)) {
                continue;
            }

            $action = ltrim($route->getActionName(), '\\');
            if ($route->getControllerClass() !== null && ! str_contains($action, '@')) {
                $action .= '@__invoke';
            }

            $bindings = [];
            $component = null;
            foreach ($queries as $query) {
                if ($query->standalone || $query->optionSource !== null || ltrim($query->action, '\\') !== $action) {
                    continue;
                }

                $component = $query->component;
                $binding = ['query' => $query->name(), 'one' => $query->expression->singular,
                    'parameters' => $this->parameters($route, $query)];
                foreach ($query->parameters as $type) {
                    if (str_starts_with($type, 'request:')) {
                        foreach (RequestContract::value(substr($type, 8))->fields as $field => $contract) {
                            if (($choices = $contract->choices()) !== null) {
                                $binding['options'][$field] = $choices;
                            }
                        }
                    }
                }

                if ($query->guards !== []) {
                    $guard = $query->guards[0];
                    $binding['access'] = ['status' => (new PolicyCompiler)->compile($guard['class'], $guard['ability'])->deniedStatus];
                }

                $bindings[$query->prop] = $binding;
            }

            // Symfony owns optional segments, domain placeholders, and escaping.
            // Requirements are omitted only for conservative matching: overlaps fall back to HTTP.
            $compiled = $route->toSymfonyRoute()->setRequirements([])->compile();
            $routes[] = [
                'pattern' => $this->pattern($compiled->getRegex(), $compiled, $route->wheres !== []),
                'host' => $compiled->getHostRegex() === null ? null : $this->pattern($compiled->getHostRegex(), $compiled, $route->wheres !== []),
                'protocol' => $route->httpsOnly() ? 'https:' : ($route->httpOnly() ? 'http:' : null),
                'route' => ControllerRouteDispatcher::identity($route),
                'component' => $component,
                'defaults' => (object) array_filter($route->defaults, static fn (mixed $value): bool => is_string($value) || is_int($value)),
                'bindings' => (object) $bindings,
            ];
        }

        return 'export const navigation = '.json_encode($routes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)." as const;\n";
    }

    private function pattern(string $pattern, CompiledRoute $compiled, bool $broad): string
    {
        if ($broad) {
            foreach ([...$compiled->getTokens(), ...$compiled->getHostTokens()] as $token) {
                if (! is_array($token)) {
                    throw new LogicException('Unsupported Symfony route token.');
                }

                if (($token[0] ?? null) === 'variable' && is_string($token[2] ?? null)) {
                    // A PHP requirement may accept slashes or empty strings. Such routes
                    // must block speculation even when their portable default would not match.
                    $pattern = str_replace($token[2], '.*', $pattern);
                }
            }
        }

        // With requirements cleared Symfony emits only its portable default patterns.
        return str_replace(['(?P<', '++'], ['(?<', '+'], substr($pattern, 1, (int) strrpos($pattern, '}') - 1));
    }

    /** @return array<string, array{route: string, type: string, values?: list<string|int>}|array{query: list<string>}>|null */
    private function parameters(Route $route, ControllerQuery $query): ?array
    {
        if ($route->wheres !== [] || $route->enforcesScopedBindings()) {
            return null;
        }

        $names = $route->parameterNames();
        $index = 0;
        $parameters = [];
        $models = 0;
        foreach ($query->parameters as $name => $type) {
            if (str_starts_with($type, 'request:')) {
                $class = substr($type, 8);
                foreach (['prepareForValidation', 'passedValidation', 'validationData', 'withValidator', 'after'] as $hook) {
                    if (method_exists($class, $hook) && (new ReflectionMethod($class, $hook))->getDeclaringClass()->getName() !== FormRequest::class) {
                        return null;
                    }
                }

                $fields = [];
                foreach (RequestContract::value($class)->fields as $field => $contract) {
                    if ($contract->kind !== 'string') {
                        return null;
                    }

                    $fields[] = $field;
                }

                $parameters[$name] = ['query' => $fields];

                continue;
            }

            $parameter = $names[$index++] ?? null;
            if (! is_string($parameter) || resolve(Router::class)->getBindingCallback($parameter) !== null) {
                return null;
            }

            if (str_starts_with($type, 'model:')) {
                $class = substr($type, 6);
                $model = new $class;
                if (! $model instanceof Model || ++$models > 1 || $model->getRouteKeyName() !== $model->getKeyName()
                    || $route->bindingFieldFor($parameter) !== null
                    || (new ReflectionMethod($class, 'resolveRouteBinding'))->getDeclaringClass()->getName() !== Model::class) {
                    return null;
                }
            }

            $scalar = QueryInput::scalarType($type);
            if (str_starts_with($type, 'enum:')) {
                $values = QueryInput::enumValues($type);
                $scalar = is_int($values[0] ?? null) ? 'int' : 'string';
            }

            if (! in_array($scalar, ['string', 'int', 'bool'], true)) {
                return null;
            }

            $parameters[$name] = ['route' => $parameter, 'type' => $scalar];
            if (isset($values)) {
                $parameters[$name]['values'] = $values;
                unset($values);
            }
        }

        return $index === count($names) ? $parameters : null;
    }
}
