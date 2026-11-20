<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use Illuminate\Database\Eloquent\Concerns\HasUniqueStringIds;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use ReflectionMethod;
use ZeroForLaravel\Generation\RequestContract;
use ZeroForLaravel\Metadata\ModelMetadata;

/** Compile destinations, never URLs or arbitrary RedirectResponse objects.
 * @phpstan-import-type VariableType from MutationProgram
 * @phpstan-import-type Expression from MutationProgram
 */
final readonly class RedirectCompiler
{
    /** @param array<string,ModelMetadata> $models */
    public function __construct(private Router $router, private array $models, private string $action) {}

    /** The native interpreter binds by primary key only. Do not guess which row Laravel will bind. */
    public function predictsBinding(string $name, string $type): bool
    {
        $routes = array_filter($this->router->getRoutes()->getRoutes(), fn (Route $route): bool => $route->getActionName() === $this->action);
        $model = new ($this->models[$type]->class);
        $binding = new ReflectionMethod($model, 'resolveRouteBindingQuery');
        if ($routes === [] || $this->router->getBindingCallback($name) !== null
            || (new ReflectionMethod($model, 'getRouteKeyName'))->getDeclaringClass()->getName() !== Model::class
            || (new ReflectionMethod($model, 'resolveRouteBinding'))->getDeclaringClass()->getName() !== Model::class
            || ($binding->getDeclaringClass()->getName() !== Model::class && $binding->getFileName() !== (new ReflectionMethod(HasUniqueStringIds::class, 'resolveRouteBindingQuery'))->getFileName())) {
            return false;
        }

        foreach ($routes as $route) {
            if (! in_array($name, $route->parameterNames(), true) || count($route->parameterNames()) !== 1 || $route->bindingFieldFor($name) !== null || $route->enforcesScopedBindings()) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,VariableType> $locals
     * @return Expression
     */
    public function destination(Expr $expr, array $locals): array
    {
        if ($expr instanceof Expr\Ternary && $expr->if instanceof Expr) {
            $condition = $expr->cond;
            if (! $condition instanceof Expr\MethodCall || ! $condition->name instanceof Node\Identifier || $condition->name->name !== 'boolean'
                || ! $condition->var instanceof Expr\Variable || ! is_string($condition->var->name) || ($locals[$condition->var->name]['kind'] ?? null) !== 'request') {
                $this->fail($expr, 'Expected a FormRequest boolean() redirect condition.');
            }

            $args = $this->arguments($condition);
            $class = $locals[$condition->var->name]['type'] ?? '';
            if (count($args) !== 1 || ! $args[0] instanceof Node\Scalar\String_ || (RequestContract::value($class)->fields[$args[0]->value]->kind ?? null) !== 'bool') {
                $this->fail($condition, 'boolean() requires one literal validated boolean field.');
            }

            $predict = true;
            foreach (['boolean', 'prepareForValidation', 'passedValidation', 'validationData', 'withValidator', 'after'] as $hook) {
                if (method_exists($class, $hook) && (! method_exists(FormRequest::class, $hook) || (new ReflectionMethod($class, $hook))->getDeclaringClass()->getName() !== (new ReflectionMethod(FormRequest::class, $hook))->getDeclaringClass()->getName())) {
                    $predict = false;
                }
            }

            return ['op' => 'redirectWhen', 'request' => $condition->var->name, 'field' => $args[0]->value, 'predict' => $predict,
                'yes' => $this->destination($expr->if, $locals), 'no' => $this->destination($expr->else, $locals)];
        }

        if (! $expr instanceof Expr\FuncCall || ! $expr->name instanceof Node\Name) {
            $this->fail($expr, 'Expected back() or to_route() as redirect destination.');
        }

        $shadow = $expr->name->getAttribute('namespacedName');
        if ($shadow instanceof Node\Name && $shadow->toString() !== $expr->name->toString() && function_exists($shadow->toString())) {
            $this->fail($expr, 'Expected Laravel redirect helper, not a namespaced function.');
        }

        $args = $this->arguments($expr);
        if ($expr->name->toString() === 'back' && $args === []) {
            return ['op' => 'stay'];
        }

        if ($expr->name->toString() !== 'to_route' || ! ($args[0] ?? null) instanceof Node\Scalar\String_ || count($args) > 2) {
            $this->fail($expr, 'Expected to_route(literal name, literal parameter map) or back().');
        }

        $name = $args[0]->value;
        $routes = array_values(array_filter($this->router->getRoutes()->getRoutes(), fn (Route $route): bool => $route->getName() === $name));
        if (count($routes) !== 1 || ! in_array('GET', $routes[0]->methods(), true)) {
            $this->fail($expr, 'Redirect requires an unambiguous named GET route: '.$name);
        }

        $route = $routes[0];
        $parameters = [];
        $map = $args[1] ?? new Expr\Array_;
        if (! $map instanceof Expr\Array_) {
            $this->fail($map, 'Expected a literal redirect parameter map.');
        }

        foreach ($map->items as $item) {
            if ($item === null || $item->unpack || $item->byRef || ! $item->key instanceof Node\Scalar\String_ || isset($parameters[$item->key->value]) || ! in_array($item->key->value, $route->parameterNames(), true)) {
                $this->fail($expr, 'Expected distinct named route parameters without query extras.');
            }

            $key = $item->key->value;
            $value = $item->value;
            $argument = ['name' => $key];
            if ($value instanceof Node\Scalar\String_ || $value instanceof Node\Scalar\Int_) {
                $argument['value'] = ['op' => 'literal', 'value' => $value->value];
            } elseif ($value instanceof Expr\Variable && is_string($value->name) && isset($locals[$value->name])) {
                $local = $locals[$value->name];
                $argument['value'] = ['op' => 'local', 'name' => $value->name];
                if ($local['kind'] === 'model' && ! ($local['nullable'] ?? false)) {
                    $meta = $this->models[$local['type'] ?? ''];
                    $model = new $meta->class;
                    $field = $route->bindingFieldFor($key);
                    // PHP reads the actual key (including custom accessors). Only the
                    // prepared/stored primary identity is guaranteed available locally.
                    $argument['model'] = true;
                    $argument['bindingField'] = $field;
                    $ordinaryKey = (new ReflectionMethod($model, 'getRouteKey'))->getDeclaringClass()->getName() === Model::class
                        && (new ReflectionMethod($model, 'getRouteKeyName'))->getDeclaringClass()->getName() === Model::class;
                    $argument['predictKey'] = ($field === $meta->key || ($field === null && $ordinaryKey))
                        && ! $model->hasGetMutator($meta->key) && ! $model->hasAttributeGetMutator($meta->key) ? $meta->key : null;
                } elseif ($local['kind'] !== 'scalar' || ! in_array($local['type'] ?? null, ['int', 'string'], true)) {
                    $this->fail($value, 'Unsupported route parameter local; expected a model or route scalar.');
                }
            } else {
                $this->fail($value, 'Unsupported route parameter; expected a literal string/integer or bound local.');
            }

            $parameters[$key] = $argument;
        }

        if (count($route->parameterNames()) !== count($parameters)) {
            $this->fail($expr, 'Supply every redirect route parameter explicitly.');
        }

        return ['op' => 'visit', 'route' => $name, 'parameters' => array_values($parameters)];
    }

    /** @return list<Expr> */
    private function arguments(Expr\CallLike $call): array
    {
        $args = [];
        foreach ($call->getRawArgs() as $arg) {
            if (! $arg instanceof Node\Arg || $arg->unpack || $arg->byRef || $arg->name instanceof Node\Identifier) {
                $this->fail($call, 'Redirect calls require positional arguments.');
            }

            $args[] = $arg->value;
        }

        return $args;
    }

    private function fail(Node $node, string $message): never
    {
        throw new LogicException($this->action.':'.$node->getStartLine().': '.$message);
    }
}
