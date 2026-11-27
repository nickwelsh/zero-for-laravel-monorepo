<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Auth\Access\Gate as LaravelGate;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Gate;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionProperty;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Generation\ScopeCompiler;
use ZeroForLaravel\Metadata\ModelRegistry;

final class PolicyCompiler
{
    /** @param class-string<Model> $class */
    public function compileClass(string $class, string $ability): PolicyPlan
    {
        return $this->compile($class, $ability, true);
    }

    /** @param class-string<Model> $class */
    public function compile(string $class, string $ability = 'view', bool $classResource = false, bool $onScopedRows = false): PolicyPlan
    {
        $gate = Gate::getFacadeRoot();
        if (! $gate instanceof LaravelGate) {
            throw new LogicException("Portable policy queries require Laravel's ordinary Gate implementation.");
        }

        foreach (['beforeCallbacks', 'afterCallbacks'] as $property) {
            if ((new ReflectionProperty($gate, $property))->getValue($gate) !== []) {
                throw new LogicException('Global Gate before/after hooks are server-only; portable queries cannot ignore them.');
            }
        }

        $model = new $class;
        if (! $classResource && ! method_exists($model, 'scopeVisibleTo')) {
            throw new LogicException("{$class} requires HasPolicyVisibility for portable collections.");
        }

        if (! $classResource) {
            $scope = new ReflectionMethod($model, 'scopeVisibleTo');
            $supportedScope = new ReflectionMethod(HasPolicyVisibility::class, 'scopeVisibleTo');
            $attribute = method_exists($model, 'visibleTo') ? new ReflectionMethod($model, 'visibleTo') : null;
            if ($scope->getFileName() !== $supportedScope->getFileName() || $scope->getStartLine() !== $supportedScope->getStartLine() || ($attribute instanceof ReflectionMethod && ! $attribute->isPrivate() && $attribute->getAttributes(Scope::class) !== [])) {
                throw new LogicException('Custom visibleTo scopes are server-only; portable queries cannot replace application SQL.');
            }

            if (! $onScopedRows) {
                $this->assertProjectionScopes($model);
            }
        }

        $policy = $gate->getPolicyFor($class);
        if (! is_object($policy) || ! method_exists($policy, $ability)) {
            throw new LogicException("{$class} requires a Laravel policy with an instance {$ability} method, including for admins.");
        }

        $policyClass = $policy::class;
        $method = new ReflectionMethod($policy, $ability);
        $node = $this->method($method);
        if (count($node->params) !== ($classResource ? 1 : 2) || count($node->stmts ?? []) !== 1 || ! $node->stmts[0] instanceof Stmt\Return_) {
            throw new LogicException("{$policyClass}::{$ability} is not portable: expected a direct Casbin::inspect return.");
        }

        $parameters = $method->getParameters();
        $subjectType = $parameters[0]->getType();
        $resourceType = isset($parameters[1]) ? $parameters[1]->getType() : null;
        if (! $subjectType instanceof ReflectionNamedType
            || ! is_a(config()->string('permissions.subject.class'), $subjectType->getName(), true)
            || (! $classResource && (! $resourceType instanceof ReflectionNamedType || ! is_a($class, $resourceType->getName(), true)))) {
            throw new LogicException('Portable instance policies require the configured subject and resource parameter types.');
        }

        $return = $node->stmts[0]->expr;
        $status = config()->integer('permissions.denied_status');
        $revocation = null;
        $denialOverride = false;
        while ($return instanceof Expr\MethodCall) {
            if ($return->name instanceof Identifier && $return->name->name === 'onRevoked' && ! $revocation instanceof Revocation) {
                $arguments = $this->arguments($return, 1);
                $revocation = $this->revocation($arguments[0]);
                $return = $return->var;

                continue;
            }

            if (! $return->name instanceof Identifier || $return->args !== [] || $denialOverride || ! in_array($return->name->name, ['asNotFound', 'asNotAuthorized'], true)) {
                throw new LogicException('Portable policy denial overrides are asNotFound() or asNotAuthorized().');
            }

            $denialOverride = true;
            $status = $return->name->name === 'asNotFound' ? 404 : 403;
            $return = $return->var;
        }

        if (! in_array($status, [403, 404], true)) {
            throw new LogicException('Permission denial status must be 403 or 404.');
        }

        $constant = null;
        $action = $ability;
        if ($return instanceof Expr\ConstFetch && in_array($return->name->toLowerString(), ['true', 'false'], true)) {
            $constant = $return->name->toLowerString() === 'true';
        } elseif ($return instanceof Expr\StaticCall && $return->class instanceof Name && $return->class->toString() === Response::class
            && $return->name instanceof Identifier && in_array($return->name->name, ['allow', 'deny'], true) && $return->args === []) {
            $constant = $return->name->name === 'allow';
        } else {
            $arguments = $return instanceof Expr\StaticCall ? $return->getArgs() : [];
            if (! $return instanceof Expr\StaticCall || ! $return->class instanceof Name || $return->class->toString() !== Casbin::class
                || ! $return->name instanceof Identifier || $return->name->name !== 'inspect' || count($arguments) !== 3
                || ! $arguments[0] instanceof Node\Arg || ! $arguments[1] instanceof Node\Arg || ! $arguments[2] instanceof Node\Arg
                || ! $arguments[1]->value instanceof String_ || ! $this->sameVariable($arguments[0]->value, $node->params[0]->var)
                || ($classResource ? ! $this->classLiteral($arguments[2]->value, $class) : ! $this->sameVariable($arguments[2]->value, $node->params[1]->var))) {
                throw new LogicException("{$policyClass}::{$ability} is not portable: expected Casbin::inspect(\$user, 'literal-action', \$resource).");
            }

            foreach ($arguments as $argument) {
                if ($argument->name !== null || $argument->unpack) {
                    throw new LogicException('Portable inspect calls require positional arguments.');
                }
            }

            $action = $arguments[1]->value->value;
        }

        $admin = null;
        $beforeSource = '';
        if (method_exists($policy, 'before')) {
            $before = new ReflectionMethod($policy, 'before');
            $hook = $this->method($before);
            $expression = count($hook->stmts ?? []) === 1 && $hook->stmts[0] instanceof Stmt\Return_ ? $hook->stmts[0]->expr : null;
            if (count($hook->params) !== 2 || ! $expression instanceof Expr\Ternary || ! $expression->cond instanceof Expr\PropertyFetch
                || ! $expression->cond->name instanceof Identifier || ! $this->sameVariable($expression->cond->var, $hook->params[0]->var)
                || ! $expression->if instanceof Expr\ConstFetch || $expression->if->name->toLowerString() !== 'true'
                || ! $expression->else instanceof Expr\ConstFetch || $expression->else->name->toLowerString() !== 'null') {
                throw new LogicException("{$policyClass}::before is server-only. Portable admin overrides require return \$user->boolean_attribute ? true : null.");
            }

            $admin = $expression->cond->name->name;
            $attributes = Binding::attributes(config()->get('permissions.subject.attributes'));
            $subjectClass = config()->string('permissions.subject.class');
            if (! is_subclass_of($subjectClass, Model::class)) {
                throw new LogicException('Permission subjects must be Eloquent model classes.');
            }

            $subjectModel = new $subjectClass;
            if (($attributes[$admin]['type'] ?? null) !== 'boolean' || $attributes[$admin]['column'] !== $admin
                || $subjectModel->hasGetMutator($admin) || $subjectModel->hasAttributeGetMutator($admin)
                || ! in_array($subjectModel->getCasts()[$admin] ?? null, [null, 'bool', 'boolean'], true)) {
                throw new LogicException('Portable admin overrides require a declared non-null database boolean subject attribute.');
            }

            $beforeSource = $this->source($before);
        }

        $binding = Binding::resource($class);
        $portable = PortableModel::compile(resolve(PermissionManager::class)->model($binding->model)->source(), $binding);
        $fingerprint = hash('sha256', json_encode([$policy::class, $this->source($method), $beforeSource, $binding, config()->array('permissions.subject'), $portable->fingerprint, $status], JSON_THROW_ON_ERROR));

        return new PolicyPlan($binding, $portable, $action, $status, $admin, $constant, $fingerprint, $revocation, $classResource);
    }

    /** A detached permission projection cannot observe arbitrary changes in row membership. */
    private function assertProjectionScopes(Model $model): void
    {
        $business = array_filter($model->getGlobalScopes(), static fn (mixed $scope): bool => ! is_object($scope) || ($scope::class !== SoftDeletingScope::class && ! $scope instanceof VisibleToCurrentUser));
        if ($business === []) {
            return;
        }

        foreach (resolve(ModelRegistry::class)->all() as $metadata) {
            if ($metadata->class === $model::class) {
                foreach (array_intersect_key(ScopeCompiler::globals($metadata), $business) as $scope) {
                    if (! ScopeCompiler::creationOnly($scope)) {
                        throw new LogicException($model::class.': detached permission projections cannot track a membership-changing global scope.');
                    }
                }

                return;
            }
        }

        throw new LogicException($model::class.': cannot translate global scopes without participating model metadata.');
    }

    private function classLiteral(Expr $expression, string $class): bool
    {
        return $expression instanceof Expr\ClassConstFetch && $expression->class instanceof Name
            && $expression->class->toString() === $class && $expression->name instanceof Identifier && $expression->name->toLowerString() === 'class';
    }

    /** @return list<Expr> */
    private function arguments(Expr\CallLike $call, int $count): array
    {
        $arguments = $call->getArgs();
        if (count($arguments) !== $count) {
            throw new LogicException('Portable revocation declarations require literal positional arguments.');
        }

        $values = [];
        foreach ($arguments as $argument) {
            if (! $argument instanceof Node\Arg || $argument->name instanceof Identifier || $argument->unpack) {
                throw new LogicException('Portable revocation declarations require literal positional arguments.');
            }

            $values[] = $argument->value;
        }

        return $values;
    }

    private function revocation(Expr $expression): Revocation
    {
        $message = null;
        if ($expression instanceof Expr\MethodCall) {
            if (! $expression->name instanceof Identifier || $expression->name->name !== 'message') {
                throw new LogicException('Portable revocation metadata only supports a literal message().');
            }

            $arguments = $this->arguments($expression, 1);
            if (! $arguments[0] instanceof String_) {
                throw new LogicException('Portable revocation messages must be string literals.');
            }

            $message = $arguments[0]->value;
            $expression = $expression->var;
        }

        if (! $expression instanceof Expr\StaticCall || ! $expression->class instanceof Name || $expression->class->toString() !== Revocation::class
            || ! $expression->name instanceof Identifier || $expression->name->name !== 'redirectToRoute') {
            throw new LogicException('Portable onRevoked requires Revocation::redirectToRoute(), not callbacks or dynamic expressions.');
        }

        $arguments = $this->arguments($expression, 1);
        if (! $arguments[0] instanceof String_) {
            throw new LogicException('Portable revocation destinations must be literal route names.');
        }

        $revocation = Revocation::redirectToRoute($arguments[0]->value);
        $revocation->assertDestination();

        return $message === null ? $revocation : $revocation->message($message);
    }

    private function sameVariable(Expr $a, Expr $b): bool
    {
        return $a instanceof Expr\Variable && $b instanceof Expr\Variable && is_string($a->name) && $a->name === $b->name;
    }

    private function method(ReflectionMethod $method): Stmt\ClassMethod
    {
        $source = $this->source($method);
        $nodes = (new NodeTraverser(new NameResolver))->traverse((new ParserFactory)->createForHostVersion()->parse($source) ?? []);
        $node = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->getStartLine() === $method->getStartLine());
        if (! $node instanceof Stmt\ClassMethod || ! $node->isPublic() || $node->isStatic()) {
            throw new LogicException('Portable policy methods must be public PHP source methods.');
        }

        return $node;
    }

    private function source(ReflectionMethod $method): string
    {
        $file = $method->getFileName();
        if ($file === false || ($source = file_get_contents($file)) === false) {
            throw new LogicException('Portable policy source is unavailable.');
        }

        return $source;
    }
}
