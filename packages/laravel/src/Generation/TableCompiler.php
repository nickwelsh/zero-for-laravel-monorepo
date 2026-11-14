<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionMethod;
use Throwable;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\Tables\AllowedFilter;
use ZeroForLaravel\Tables\AllowedGroup;
use ZeroForLaravel\Tables\Predicate;
use ZeroForLaravel\Tables\Table;
use ZeroForLaravel\Tables\TableDefinition;

/** Evaluate only known declaration operations, never arbitrary application PHP. */
final class TableCompiler
{
    private bool $visible = false;

    private ?QueryExpression $expression = null;

    public function __construct(private readonly ModelRegistry $models) {}

    public function compile(string $name, string $class): TableDefinition
    {
        if (! preg_match('/^[a-z][a-z0-9_-]*$/D', $name)) {
            throw new LogicException('Table aliases must be lowercase URL-safe names.');
        }

        $this->visible = false;
        $this->expression = null;
        $method = new ReflectionMethod($class, 'definition');
        $file = $method->getFileName() ?: throw new LogicException('Table definition source is unavailable.');
        $parser = (new ParserFactory)->createForHostVersion();
        $nodes = (new NodeTraverser(new NameResolver))->traverse($parser->parse(file_get_contents($file) ?: '') ?? []);
        $node = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->name === 'definition' && $node->getStartLine() === $method->getStartLine());
        if (! $node instanceof Stmt\ClassMethod || count($node->stmts ?? []) !== 1 || ! $node->stmts[0] instanceof Stmt\Return_ || ! $node->stmts[0]->expr instanceof Expr) {
            throw new LogicException($file.':'.$method->getStartLine().': table definitions require a single declarative return expression.');
        }

        try {
            $table = $this->value($node->stmts[0]->expr);
            if (! $table instanceof Table) {
                throw new LogicException('Expected Table::for(...).');
            }

            $expression = $this->queryExpression();
            $visible = $this->isVisible();
            if ($table->base->model->policyVisibility && ! $visible) {
                throw new LogicException('Protected tables require visibleTo($context->user()).');
            }

            $table->grouping($table->defaultGrouping);
            $table->ordering($table->defaultOrdering);
            // Compile deferred scopes using the existing checked construction compiler.
            $expression->emit(true);
            $ast = $table->base->ast();
            if (array_diff(array_keys($ast), ['table', 'where']) !== []) {
                throw new LogicException('Table base scopes must declare portable membership only, without limits, cursors or ordering.');
            }

            if (isset($ast['where'])) {
                Predicate::sql((new $table->base->model->class)->newQueryWithoutScopes()->getQuery(), Input::object($ast['where'], 'scope'), $table->base->model);
            }

            $dependencies = [];
            foreach (array_unique([$table->base->model->name, ...array_filter(array_column($table->groups, 'model'))]) as $modelName) {
                $model = $this->models->get($modelName);
                $schema = $model->toArray();
                // Unrelated relationships are discovered lazily by other compilers.
                unset($schema['relations'], $schema['relationConstraints']);
                $dependencies[$modelName] = [$schema, $model->policyVisibility ? (new PolicyCompiler)->compile($model->class)->fingerprint : null];
            }

            ksort($dependencies);
            $fingerprint = hash('sha256', serialize([2, $name, hash_file('sha256', $file), $dependencies, $table->groups, ContextDefinition::configured()->contract(), config()->string('zero.route_prefix')]));

            return new TableDefinition($name, $table, $expression, $fingerprint, $visible);
        } catch (Throwable $throwable) {
            throw new LogicException($file.':'.$node->getStartLine().': table '.$name.': '.$throwable->getMessage(), previous: $throwable);
        }
    }

    private function value(Expr $node): mixed
    {
        if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            return $node->value;
        }

        if ($node instanceof Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['true', 'false', 'null'], true)) {
            return match (strtolower($node->name->toString())) {
                'true' => true, 'false' => false, default => null
            };
        }

        if ($node instanceof Expr\UnaryMinus && ($node->expr instanceof Scalar\Int_ || $node->expr instanceof Scalar\Float_)) {
            return -$node->expr->value;
        }

        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = $node->class->toString();
            if (strtolower($node->name->name) === 'class') {
                return $class;
            }

            if ($class === ContextDefinition::configured()->enum && defined($class.'::'.$node->name->name)) {
                return constant($class.'::'.$node->name->name);
            }
        }

        if ($node instanceof Expr\Array_) {
            $items = [];
            foreach ($node->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || ($item->key !== null && ! $item->key instanceof Scalar\String_)) {
                    throw new LogicException('Table declaration arrays must be literal lists or string-keyed objects.');
                }

                if ($item->key instanceof Scalar\String_) {
                    if (array_key_exists($item->key->value, $items)) {
                        throw new LogicException('Duplicate table declaration key.');
                    }

                    $items[$item->key->value] = $this->value($item->value);
                } else {
                    $items[] = $this->value($item->value);
                }
            }

            return $items;
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            $class = $node->class->toString();
            $method = $node->name->name;
            $args = $this->arguments($node);
            if ($class === Table::class && $method === 'for' && count($args) === 1 && is_string($args[0])) {
                $model = null;
                foreach ($this->models->all() as $candidate) {
                    if ($candidate->class === $args[0]) {
                        $model = $candidate;
                    }
                }

                if ($model === null) {
                    throw new LogicException('Table model must be registered with Zero.');
                }

                $this->expression = new QueryExpression('query', model: $model, type: 'query');

                return Table::for($model->class);
            }

            if (($class === AllowedFilter::class && in_array($method, ['exact', 'text', 'partial', 'number', 'date'], true)) || ($class === AllowedGroup::class && in_array($method, ['field', 'belongsTo'], true))) {
                return $class::$method(...$args);
            }
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $target = $this->value($node->var);
            $method = $node->name->name;
            if ($target instanceof Table && $method === 'visibleTo') {
                $args = $node->getArgs();
                $user = $args[0]->value ?? null;
                if (count($args) !== 1 || ! $user instanceof Expr\MethodCall || ! $user->var instanceof Expr\Variable || $user->var->name !== 'context' || ! $user->name instanceof Identifier || $user->name->name !== 'user' || $user->getArgs() !== []) {
                    throw new LogicException('Table visibility requires $context->user().');
                }

                $this->visible = true;
                $target->base->withoutGlobalScope(VisibleToCurrentUser::class);
                $this->expression = new QueryExpression('method', 'visibleTo', [$this->expression ?? throw new LogicException('Missing table root.'), new QueryExpression('actor', type: 'actor')], $target->base->model, 'query');

                return $target;
            }

            $allowed = match (true) {
                $target instanceof Table => ['allowedFilters', 'allowedSorts', 'allowedGroups', 'defaultSort', 'defaultGroupBy', 'pageSize', 'maxGroupDepth', 'where'],
                $target instanceof AllowedFilter => ['operators', 'nullable'],
                $target instanceof AllowedGroup => ['label'],
                default => [],
            };
            if (! in_array($method, $allowed, true)) {
                throw new LogicException('Unsupported table declaration method '.$method.'.');
            }

            $args = $this->arguments($node);
            if ($target instanceof Table && $method === 'where') {
                $this->expression = new QueryExpression('method', 'where', [$this->expression ?? throw new LogicException('Missing table root.'), ...array_map(ScopeCompiler::value(...), $args)], $target->base->model, 'query');
            }

            return (new ReflectionMethod($target, $method))->invokeArgs($target, $args);
        }

        throw new LogicException('Unsupported table expression at line '.$node->getStartLine().'.');
    }

    /** @return list<mixed> */
    private function arguments(Expr\StaticCall|Expr\MethodCall $call): array
    {
        $values = [];
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack || $argument->byRef || $argument->name !== null) {
                throw new LogicException('Use positional literal table arguments.');
            }

            $values[] = $this->value($argument->value);
        }

        return $values;
    }

    private function queryExpression(): QueryExpression
    {
        return $this->expression ?? throw new LogicException('Missing table root.');
    }

    private function isVisible(): bool
    {
        return $this->visible;
    }
}
