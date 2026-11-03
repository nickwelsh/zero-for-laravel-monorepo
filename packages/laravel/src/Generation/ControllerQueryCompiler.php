<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use BackedEnum;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
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
use PhpParser\Parser;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionObject;
use ReflectionParameter;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\PolicyCompiler;
use ZeroForLaravel\Query\QueryInput;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\Scopes\ScopeRegistry;

/** Pure declarations only: an unrepresented guard or dependency is a build error. */
final class ControllerQueryCompiler
{
    private string $file = '';

    private string $action = '';

    private string $prop = '*';

    private int $callbackId = 0;

    /** @var list<array{parameter: string, class: class-string<Model>, ability: string, fingerprint: string}> */
    private array $guards = [];

    /** @var list<string> */
    private array $scopeStack = [];

    public function __construct(private readonly Parser $parser, private readonly ModelRegistry $models) {}

    /** @param array<string, QueryExpression> $variables */
    public function construction(Expr $expression, array $variables = []): QueryExpression
    {
        return $this->expression($expression, $variables)->snapshot();
    }

    /**
     * @param  list<QueryExpression>  $arguments
     */
    public function scope(ReflectionMethod|Closure $scope, ModelMetadata $model, array $arguments = [], ?object $instance = null): QueryExpression
    {
        $reflection = $scope instanceof Closure ? new ReflectionFunction($scope) : $scope;
        $id = ($scope instanceof ReflectionMethod ? $scope->class.'::'.$scope->name : $reflection->getFileName().':'.$reflection->getStartLine());
        if (in_array($id, $this->scopeStack, true)) {
            throw new LogicException('Recursive Zero local scope: '.implode(' -> ', [...$this->scopeStack, $id]));
        }

        $this->scopeStack[] = $id;
        $previous = [$this->file, $this->action, $this->prop];
        $this->file = $reflection->getFileName() ?: throw new LogicException('Scope source is unavailable.');
        $this->action = $id;
        $this->prop = 'scope';
        try {
            $nodes = (new NodeTraverser(new NameResolver))->traverse($this->parser->parse(file_get_contents($this->file) ?: '') ?? []);
            $node = (new NodeFinder)->findFirst($nodes, fn (Node $candidate): bool => ($candidate instanceof Stmt\ClassMethod || $candidate instanceof Expr\Closure || $candidate instanceof Expr\ArrowFunction) && $candidate->getStartLine() <= $reflection->getStartLine() && $candidate->getEndLine() === $reflection->getEndLine() && ($scope instanceof Closure ? ! $candidate instanceof Stmt\ClassMethod : $candidate instanceof Stmt\ClassMethod && $candidate->name->name === $scope->name));
            if (! $node instanceof Stmt\ClassMethod && ! $node instanceof Expr\Closure && ! $node instanceof Expr\ArrowFunction) {
                throw new LogicException($id.': cannot locate scope body.');
            }

            $parameter = $node->params[0] ?? null;
            if ($parameter === null || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name)) {
                $this->unsupported($node, 'A scope requires a query parameter.');
            }

            $name = $parameter->var->name;
            $local = QueryExpression::reference(new QueryExpression('local', 'zeroScope', model: $model, type: 'query'));
            $variables = [$name => $local];
            foreach (array_slice($node->params, 1) as $index => $param) {
                if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name) || $param->byRef || $param->variadic) {
                    $this->unsupported($param, 'Scopes require ordinary named arguments.');
                }

                if ($param->type instanceof Name && $param->type->toString() === Model::class) {
                    $variables[$param->var->name] = new QueryExpression('metadata', model: $model, type: 'metadata');
                } else {
                    $variables[$param->var->name] = $arguments[$index] ?? ($param->default !== null ? $this->expression($param->default, []) : throw new LogicException($id.': missing scope argument '.$param->var->name));
                }
            }

            if ($scope instanceof Closure) {
                foreach ($reflection->getStaticVariables() as $key => $value) {
                    if (is_string($key)) {
                        $variables[$key] = ScopeCompiler::value($value);
                    }
                }
            } elseif ($instance !== null) {
                $state = [];
                foreach ((new ReflectionObject($instance))->getProperties() as $property) {
                    if (! $property->isStatic() && $property->isInitialized($instance)) {
                        $state[] = new QueryExpression('property', $property->name, [ScopeCompiler::value($property->getValue($instance))]);
                    }
                }

                $variables['this'] = new QueryExpression('state', children: $state, type: 'state');
            }

            if ($node instanceof Expr\ArrowFunction) {
                return $this->expression($node->expr, $variables)->snapshot();
            }

            $statements = $node->stmts ?? [];
            $return = end($statements);
            if ($return instanceof Stmt\Return_) {
                array_pop($statements);
            }

            $variables = $this->statements($statements, $variables);
            if ($return instanceof Stmt\Return_ && $return->expr instanceof Expr) {
                $result = $this->expression($return->expr, $variables);
                if ($result !== $variables[$name]) {
                    $this->unsupported($return, 'Scopes must return the supplied query or void.');
                }
            }

            return $variables[$name]->snapshot();
        } finally {
            [$this->file, $this->action, $this->prop] = $previous;
            array_pop($this->scopeStack);
        }
    }

    /** @return list<ControllerQuery> */
    public function compile(string $controller, string $method): array
    {
        $reflection = new ReflectionMethod($controller, $method);
        $this->file = $reflection->getFileName() ?: throw new LogicException('Controller source is unavailable.');
        $this->action = ltrim($controller, '\\').'@'.$method;
        $this->prop = '*';
        $this->callbackId = 0;
        $this->guards = [];
        $source = file_get_contents($this->file);
        if ($source === false) {
            throw new LogicException("Cannot read controller {$this->file}.");
        }

        $nodes = (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($source) ?? []);
        $node = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->name === $method && $node->getEndLine() === $reflection->getEndLine());
        if (! $node instanceof Stmt\ClassMethod || $node->stmts === null) {
            throw new LogicException("{$this->file}: {$this->action}: controller body is unavailable.");
        }

        $declaration = (new NodeFinder)->findFirst($node->stmts, fn (Node $candidate): bool => $candidate instanceof Expr\StaticCall && $candidate->class instanceof Name && $candidate->name instanceof Identifier && ($candidate->name->name === 'zeroQuery' || class_exists($candidate->class->toString()) && ScopeRegistry::local($candidate->class->toString(), $candidate->name->name) instanceof ReflectionMethod));
        $boundModels = array_filter($reflection->getParameters(), fn (ReflectionParameter $parameter): bool => $parameter->getType() instanceof ReflectionNamedType && is_subclass_of($parameter->getType()->getName(), Model::class));
        $render = (new NodeFinder)->findFirst($node->stmts, fn (Node $candidate): bool => $candidate instanceof Expr\StaticCall && $candidate->class instanceof Name && $candidate->class->toString() === Inertia::class && $candidate->name instanceof Identifier && $candidate->name->name === 'render');
        if (! $declaration instanceof Node && ($boundModels === [] || ! $render instanceof Node)) {
            return [];
        }

        $parameters = [];
        $variables = [];
        foreach ($node->params as $parameter) {
            if (! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name) || (! $parameter->type instanceof Identifier && ! $parameter->type instanceof Name) || $parameter->variadic || $parameter->byRef || $parameter->default !== null) {
                $this->unsupported($parameter, 'Expected a required typed controller input.');
            }

            $name = $parameter->var->name;
            if (str_starts_with($name, '__')) {
                $this->unsupported($parameter, 'Double-underscore input names are reserved for transport metadata.');
            }

            $type = $parameter->type->toString();
            $matches = array_values(array_filter($this->models->all(), fn (ModelMetadata $model): bool => $model->class === $type));
            $model = $matches[0] ?? null;
            if ($model !== null) {
                $parameters[$name] = 'model:'.$type;
                $variables[$name] = new QueryExpression('bound', $name, model: $model, type: 'query', singular: true);
            } elseif ($type === Request::class) {
                $variables[$name] = new QueryExpression('request', $name, type: 'http-request');
            } elseif (is_subclass_of($type, BackedEnum::class)) {
                $parameters[$name] = 'enum:'.$type;
                $variables[$name] = new QueryExpression('enum', $name, array_map(fn (BackedEnum $case): QueryExpression => new QueryExpression('literal', $case->value), $type::cases()), type: get_debug_type($type::cases()[0]->value));
            } elseif (is_subclass_of($type, FormRequest::class)) {
                if (array_filter($parameters, fn (string $value): bool => str_starts_with($value, 'request:')) !== []) {
                    $this->unsupported($parameter, 'Use one FormRequest per action.');
                }

                RequestContract::rules($type);
                $parameters[$name] = 'request:'.$type;
                $variables[$name] = new QueryExpression('request', $name, type: 'request:'.$type);
            } elseif (in_array($type, ['string', 'bool', 'int'], true)) {
                $parameters[$name] = $type;
                $variables[$name] = new QueryExpression('argument', $name, type: $type);
            } else {
                $this->unsupported($parameter, 'Unsupported controller input '.$type);
            }
        }

        $statements = $node->stmts;
        $return = array_pop($statements);
        if (! $return instanceof Stmt\Return_ || ! $return->expr instanceof Expr\StaticCall || ! $return->expr->class instanceof Name || $return->expr->class->toString() !== Inertia::class || ! $return->expr->name instanceof Identifier || $return->expr->name->name !== 'render') {
            $this->unsupported($node, 'Expected one final Inertia::render response.');
        }

        $args = $return->expr->getArgs();
        if (count($args) !== 2 || ! $args[0]->value instanceof Scalar\String_ || ! $args[1]->value instanceof Expr\Array_) {
            $this->unsupported($return, 'Expected a literal component name and named prop array.');
        }

        $variables = $this->statements($statements, $variables);
        $models = $this->models->all();
        ksort($models);
        $schemaFingerprint = hash('sha256', json_encode([
            'zero' => '1.11.3', 'builder' => 'mutable-scopes-v1', 'context' => ContextDefinition::configured()->contract(),
            'models' => array_map(function (ModelMetadata $model): array {
                $schema = $model->toArray();
                // Lazy query dependencies belong to the query, not discovery order.
                $schema['relations'] = [];
                $schema['relationConstraints'] = [];

                return $schema;
            }, $models),
            'inputs' => array_map(static fn (string $type): mixed => match (true) {
                str_starts_with($type, 'request:') => RequestContract::rules(substr($type, 8)),
                str_starts_with($type, 'enum:') => QueryInput::enumValues($type),
                default => $type,
            }, $parameters),
        ], JSON_THROW_ON_ERROR));
        $props = [];
        $ordinary = [];
        foreach ($args[1]->value->items as $item) {
            if ($item === null || $item->unpack || ! $item->key instanceof Scalar\String_) {
                $this->unsupported($return, 'Response props must have explicit string keys.');
            }

            $this->prop = $item->key->value;
            $expression = $this->expression($item->value, $variables)->snapshot();
            if ($expression->kind === 'bound' && $expression->model?->policyVisibility) {
                $guard = array_values(array_filter($this->guards, fn (array $guard): bool => $guard['parameter'] === $expression->value))[0] ?? null;
                if ($guard === null) {
                    $this->unsupported($item, 'Protected bound props require a captured instance Gate::authorize.');
                }

                $expression = new QueryExpression('guarded', $guard['ability'], [$expression], $expression->model, 'query', true);
            }

            if ($expression->type === 'query') {
                $this->coverage($expression);
            }

            $props[$this->prop] = [$expression, $item->getStartLine()];
            if (! $expression->model instanceof ModelMetadata) {
                $ordinary[$this->prop] = match ($expression->type) {
                    'string' => 'string', 'int' => 'number', 'bool' => 'boolean', 'null' => 'null',
                    default => $this->unsupported($item, 'Ordinary prop requires a supported inferred contract.'),
                };
            }
        }

        $queries = [];
        foreach ($props as $prop => [$expression, $line]) {
            if ($expression->model instanceof ModelMetadata) {
                $fingerprint = hash('sha256', $schemaFingerprint.json_encode($this->dependencies($expression), JSON_THROW_ON_ERROR));
                $queries[] = new ControllerQuery($this->action, $args[0]->value->value, $prop, $parameters, $expression, $this->file, $line, $fingerprint, $ordinary, $this->guards);
            }
        }

        return $queries;
    }

    private function coverage(QueryExpression $expression, bool $inherited = false): void
    {
        if ($expression->kind === 'conditional') {
            $this->coverage($expression->children[1], $inherited);
            $this->coverage($expression->children[2], $inherited);

            return;
        }

        $covered = $inherited || $this->declaresVisibility($expression);
        for ($node = $expression; in_array($node->kind, ['method', 'scope', 'paginate', 'infinite', 'guarded'], true); $node = $node->children[0]) {
            foreach (array_slice($node->children, 1) as $child) {
                $group = $node->kind === 'scope' || $node->kind === 'method' && in_array($node->value, ['where', 'orWhere'], true);
                $this->coverage($child->kind === 'callback' ? $child->children[0] : $child, $group && $covered);
            }

            if ($node->kind === 'method' && in_array($node->value, ['with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true)
                && (! isset($node->children[2]) || $node->children[2]->kind !== 'callback')) {
                $name = $node->children[1]->value;
                if ($node->model !== null && is_string($name)) {
                    $target = $this->models->get($this->models->relation($node->model, $name)['model']);
                    if ($target->policyVisibility) {
                        throw new LogicException('Protected related paths require an explicit visibleTo refinement.');
                    }
                }
            }
        }

        if ($node->kind === 'conditional') {
            $this->coverage($node->children[1], $covered);
            $this->coverage($node->children[2], $covered);
            $covered = true;
        }

        if ($expression->type === 'query' && $expression->model?->policyVisibility && ! $covered) {
            throw new LogicException($expression->model->name.': protected query paths require visibleTo or a captured instance Gate.');
        }
    }

    private function declaresVisibility(QueryExpression $expression): bool
    {
        return match ($expression->kind) {
            'guarded' => true,
            'conditional' => $this->declaresVisibility($expression->children[1]) && $this->declaresVisibility($expression->children[2]),
            'scope' => $this->declaresVisibility($expression->children[0]) || $this->declaresVisibility($expression->children[1]),
            'method' => $expression->value === 'visibleTo' || $this->declaresVisibility($expression->children[0]),
            'paginate', 'infinite' => $this->declaresVisibility($expression->children[0]),
            default => false,
        };
    }

    /** @return list<array<string, mixed>> */
    private function dependencies(QueryExpression $expression): array
    {
        $dependencies = [];
        if ($expression->model?->policyVisibility && in_array($expression->kind, ['query', 'local', 'bound'], true)) {
            $dependencies[] = ['policy' => (new PolicyCompiler)->compile($expression->model->class)->fingerprint];
        }

        if ($expression->kind === 'method' && in_array($expression->value, ['with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true)) {
            $model = $expression->model ?? throw new LogicException('Relationship has no source model.');
            $name = $expression->children[1]->value;
            if (! is_string($name)) {
                throw new LogicException('Relationship requires a literal name.');
            }

            $relation = $this->models->relation($model, $name);
            $pivots = [];
            foreach ($relation['path'] as $path) {
                if (isset($this->models->pivotColumns()[$path['table']])) {
                    $pivots[$path['table']] = $this->models->pivotColumns()[$path['table']];
                }
            }

            $dependencies[] = ['source' => $model->name, 'relation' => $name, 'path' => $relation, 'constraints' => $model->relationConstraints[$name] ?? [], 'pivots' => $pivots];
        }

        foreach ($expression->children as $child) {
            array_push($dependencies, ...$this->dependencies($child));
        }

        return $dependencies;
    }

    private function mutate(QueryExpression $receiver, QueryExpression $value): QueryExpression
    {
        if ($receiver->kind !== 'reference') {
            return $value;
        }

        $receiver->children = [$value];
        $receiver->singular = $value->singular;

        return $receiver;
    }

    private function derivesFrom(QueryExpression $query, string $symbol): bool
    {
        return match ($query->kind) {
            'local' => $query->value === $symbol,
            'conditional' => $this->derivesFrom($query->children[1], $symbol) && $this->derivesFrom($query->children[2], $symbol),
            'method', 'scope' => $this->derivesFrom($query->children[0], $symbol),
            default => false,
        };
    }

    /** @param array<string, QueryExpression> $variables
     * @return array<string, QueryExpression>
     */
    private function branch(array $variables): array
    {
        $copies = [];
        foreach ($variables as $name => $value) {
            if ($value->kind === 'reference') {
                $variables[$name] = $copies[spl_object_id($value)] ??= QueryExpression::reference($value->snapshot());
            }
        }

        return $variables;
    }

    /**
     * @param  array<Stmt>  $statements
     * @param  array<string, QueryExpression>  $variables
     * @return array<string, QueryExpression>
     */
    private function statements(array $statements, array $variables, bool $conditional = false): array
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign && $statement->expr->var instanceof Expr\Variable && is_string($statement->expr->var->name)) {
                $variables[$statement->expr->var->name] = $this->expression($statement->expr->expr, $variables);
            } elseif ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\StaticCall
                && $statement->expr->class instanceof Name && $statement->expr->class->toString() === Gate::class
                && $statement->expr->name instanceof Identifier && $statement->expr->name->name === 'authorize') {
                $args = $statement->expr->getArgs();
                if ($conditional || count($args) !== 2 || ! $args[0]->value instanceof Scalar\String_
                    || ! $args[1]->value instanceof Expr\Variable || ! is_string($args[1]->value->name)
                    || ($variables[$args[1]->value->name]->kind ?? null) !== 'bound') {
                    $this->unsupported($statement, 'Capture only unconditional instance Gate::authorize calls on bound resources.');
                }

                foreach ($args as $arg) {
                    if ($arg->name !== null || $arg->unpack) {
                        $this->unsupported($arg, 'Gate arguments must be positional.');
                    }
                }

                $resource = $variables[$args[1]->value->name];
                $class = $resource->model->class ?? throw new LogicException('Gate resource has no model.');
                $plan = (new PolicyCompiler)->compile($class, $args[0]->value->value);
                $this->guards[] = ['parameter' => (string) $resource->value, 'class' => $class, 'ability' => $args[0]->value->value, 'fingerprint' => $plan->fingerprint];
            } elseif ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\MethodCall) {
                $this->expression($statement->expr, $variables);
            } elseif ($statement instanceof Stmt\If_ && $statement->elseifs === []) {
                $condition = $this->expression($statement->cond, $variables);
                if (! $this->boolean($condition)) {
                    $this->unsupported($statement->cond, 'A portable condition must be explicitly boolean, not PHP truthiness.');
                }

                $yes = $this->statements($statement->stmts, $this->branch($this->narrow($condition, $variables)), true);
                $no = $this->statements($statement->else->stmts ?? [], $this->branch($variables), true);
                $merged = [];
                foreach (array_unique([...array_keys($yes), ...array_keys($no)]) as $name) {
                    if (str_starts_with($name, "\0")) {
                        continue;
                    }

                    if (! isset($yes[$name], $no[$name])) {
                        $this->unsupported($statement, "Variable {$name} is not defined on both paths.");
                    }

                    if ($yes[$name]->model !== $no[$name]->model || $yes[$name]->type !== $no[$name]->type || $yes[$name]->singular !== $no[$name]->singular) {
                        $this->unsupported($statement, "Variable {$name} changes its result contract across branches.");
                    }

                    $value = new QueryExpression('conditional', children: [$condition, $yes[$name]->snapshot(), $no[$name]->snapshot()], model: $yes[$name]->model, type: $yes[$name]->type, singular: $yes[$name]->singular);
                    if ($yes[$name]->type === 'query') {
                        $identity = spl_object_id($yes[$name]).':'.spl_object_id($no[$name]);
                        $value = $merged[$identity] ??= QueryExpression::reference($value);
                    }

                    $variables[$name] = $value;
                }
            } else {
                $this->unsupported($statement, 'Statement or guard cannot be represented in the query definition.');
            }
        }

        return $variables;
    }

    /** @param array<string, QueryExpression> $variables */
    private function expression(Expr $node, array $variables): QueryExpression
    {
        if ($node instanceof Expr\Clone_) {
            $query = $this->expression($node->expr, $variables);
            if ($query->type !== 'query') {
                $this->unsupported($node, 'Only Zero queries may be cloned.');
            }

            return QueryExpression::reference($query->snapshot());
        }

        if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_ || $node instanceof Scalar\Float_) {
            if (is_int($node->value) && abs($node->value) > 9007199254740991) {
                $this->unsupported($node, 'Integer literal cannot be represented exactly by JavaScript.');
            }

            return new QueryExpression('literal', $node->value, type: get_debug_type($node->value));
        }

        if ($node instanceof Expr\ConstFetch && in_array(strtolower($node->name->toString()), ['null', 'true', 'false'], true)) {
            $value = match (strtolower($node->name->toString())) {
                'true' => true, 'false' => false, default => null
            };

            return new QueryExpression('literal', $value, type: get_debug_type($value));
        }

        if ($node instanceof Expr\Variable && is_string($node->name) && isset($variables[$node->name])) {
            return $variables[$node->name];
        }

        if ($node instanceof Expr\PropertyFetch && $node->name instanceof Identifier) {
            $receiver = $this->expression($node->var, $variables);
            if ($receiver->type === 'query' && $node->name->name === 'orWhere') {
                return new QueryExpression('higherOrder', children: [$receiver], model: $receiver->model, type: 'higherOrder');
            }

            if ($receiver->kind === 'state') {
                foreach ($receiver->children as $property) {
                    if ($property->value === $node->name->name) {
                        return $property->children[0];
                    }
                }

                $this->unsupported($node, 'Unknown portable scope property.');
            }

            if ($receiver->kind === 'context' && $node->name->name === 'value') {
                return new QueryExpression('literal', $receiver->value, type: 'string');
            }

            if ($receiver->kind === 'bound' && $node->name->name === $receiver->model?->key) {
                return new QueryExpression('argument', $receiver->value, type: $receiver->model->columns[$receiver->model->key]['type'] === 'number' ? 'int' : 'string');
            }

            if ($receiver->kind === 'enum' && $node->name->name === 'value') {
                return $receiver;
            }

            $this->unsupported($node, 'Bound query inputs expose only their canonical key.');
        }

        if ($node instanceof Expr\ArrayDimFetch && $node->dim instanceof Scalar\String_) {
            $receiver = $this->expression($node->var, $variables);
            if ($receiver->kind === 'validated') {
                $rules = RequestContract::rules(substr($receiver->type, 8));
                $field = $node->dim->value;
                if (! isset($rules[$field])) {
                    $this->unsupported($node, 'Unknown validated request field.');
                }

                $required = in_array('required', $rules[$field], true) && ! in_array('sometimes', $rules[$field], true) && ! in_array('nullable', $rules[$field], true);
                $defined = $required || isset($variables["\0".$receiver->emit(false).'.'.$field]);

                return new QueryExpression('field', $field, [$receiver], type: $defined ? RequestContract::type($rules[$field]) : 'optional');
            }
        }

        if ($node instanceof Expr\Isset_ && count($node->vars) === 1) {
            $value = $this->expression($node->vars[0], $variables);
            if ($value->kind !== 'field') {
                $this->unsupported($node, 'isset requires a validated field.');
            }

            return new QueryExpression('isset', children: [$value], type: 'bool');
        }

        if ($node instanceof Expr\FuncCall && $node->name instanceof Name && $node->name->toString() === 'array_key_exists' && count($node->args) === 2) {
            $key = $this->expression($node->getArgs()[0]->value, $variables);
            $input = $this->expression($node->getArgs()[1]->value, $variables);
            if ($key->kind !== 'literal' || ! is_string($key->value) || $input->kind !== 'validated') {
                $this->unsupported($node, 'array_key_exists requires a literal key and validated input.');
            }

            return new QueryExpression('has', children: [$key, $input], type: 'bool');
        }

        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier && is_subclass_of($node->class->toString(), BackedEnum::class)) {
            $case = constant($node->class->toString().'::'.$node->name->name);
            if ($case instanceof BackedEnum) {
                $context = ContextDefinition::configured();
                if ($case instanceof ($context->enum)) {
                    $field = (string) $case->value;
                    $contract = $context->fields[$field] ?? throw new LogicException('Unknown context field '.$field.'.');

                    return new QueryExpression('context', $field, type: $contract['type']);
                }

                return new QueryExpression('literal', $case->value, type: get_debug_type($case->value));
            }
        }

        if ($node instanceof Expr\ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier && $node->name->name === 'class') {
            return new QueryExpression('literal', $node->class->toString(), type: 'string');
        }

        if ($node instanceof Expr\Array_) {
            $items = [];
            $object = false;
            foreach ($node->items as $item) {
                if ($item === null || ($item->key !== null && ! $item->key instanceof Scalar\String_) || $item->unpack || $item->byRef) {
                    $this->unsupported($node, 'Arrays must be scalar lists or literal string-keyed objects.');
                }

                $value = $this->expression($item->value, $variables);
                if (! in_array($value->type, ['string', 'int', 'float', 'bool', 'null', 'list', 'object'], true)) {
                    $this->unsupported($item, 'Query lists require scalar values.');
                }

                if ($item->key instanceof Scalar\String_) {
                    $object = true;
                    if (array_key_exists($item->key->value, $items)) {
                        $this->unsupported($item, 'Object keys must be unique.');
                    }

                    $items[$item->key->value] = new QueryExpression('property', $item->key->value, [$value]);
                } else {
                    $items[] = $value;
                }
            }

            if ($object && array_filter($items, fn (QueryExpression $item): bool => $item->kind !== 'property') !== []) {
                $this->unsupported($node, 'Mixed list and object keys are not portable.');
            }

            return new QueryExpression($object ? 'object' : 'list', children: array_values($items), type: $object ? 'object' : 'list');
        }

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier) {
            foreach ($this->models->all() as $model) {
                if ($model->class === $node->class->toString()) {
                    $query = QueryExpression::reference(new QueryExpression('query', model: $model, type: 'query'));
                    if ($node->name->name === 'zeroQuery' && $node->args === []) {
                        return $query;
                    }

                    if (ScopeRegistry::local($model->class, $node->name->name) instanceof ReflectionMethod) {
                        return $this->expression(new Expr\MethodCall(new Expr\Variable('__entry'), $node->name, $node->args), [...$variables, '__entry' => $query]);
                    }
                }
            }

            $this->unsupported($node, 'Query model is not registered for Zero.');
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $receiver = $this->expression($node->var, $variables);
            $method = $node->name->name;
            if ($receiver->kind === 'request' && $method === 'user' && $node->args === []) {
                return new QueryExpression('actor', type: 'actor');
            }

            if ($receiver->type === 'query' && $method === 'with' && ($node->getArgs()[0]->value ?? null) instanceof Expr\Array_) {
                if (count($node->getArgs()) !== 1) {
                    $this->unsupported($node, 'Array includes take their callbacks in the keyed array.');
                }

                foreach ($node->getArgs()[0]->value->items as $item) {
                    if ($item === null || $item->unpack || $item->byRef || $item->key !== null && ! $item->key instanceof Scalar\String_) {
                        $this->unsupported($node, 'Includes require relationship names or literal keys and callbacks.');
                    }

                    $args = $item->key instanceof Scalar\String_ ? [new Node\Arg($item->key), new Node\Arg($item->value)] : [new Node\Arg($item->value)];
                    $receiver = $this->expression(new Expr\MethodCall(new Expr\Variable('__include'), 'with', $args), [...$variables, '__include' => $receiver]);
                }

                return $receiver;
            }

            if ($receiver->type === 'query' && $method === 'with' && ($node->getArgs()[0]->value ?? null) instanceof Scalar\String_ && str_contains($node->getArgs()[0]->value->value, '.')) {
                [$head, $tail] = explode('.', $node->getArgs()[0]->value->value, 2);
                $args = [new Node\Arg(new Scalar\String_($tail)), ...array_slice($node->getArgs(), 1)];
                $callback = new Expr\ArrowFunction(['params' => [new Node\Param(new Expr\Variable('__nested'))], 'expr' => new Expr\MethodCall(new Expr\Variable('__nested'), 'with', $args)]);

                return $this->expression(new Expr\MethodCall(new Expr\Variable('__include'), 'with', [new Node\Arg(new Scalar\String_($head)), new Node\Arg($callback)]), [...$variables, '__include' => $receiver]);
            }

            if ($receiver->kind === 'metadata' && $node->args === [] && in_array($method, ['getTable', 'getKeyName', 'getDeletedAtColumn'], true)) {
                return new QueryExpression('literal', match ($method) {
                    'getTable' => $receiver->queryModel()->table, 'getKeyName' => $receiver->queryModel()->key, default => $receiver->queryModel()->deletedAt,
                }, type: 'string');
            }

            if ($receiver->kind === 'higherOrder') {
                $root = $receiver->children[0];
                $local = QueryExpression::reference(new QueryExpression('local', 'zeroOr', model: $root->model, type: 'query'));
                $scope = $this->expression(new Expr\MethodCall(new Expr\Variable('__or'), $node->name, $node->args), [...$variables, '__or' => $local])->snapshot();

                return $this->mutate($root, new QueryExpression('method', 'orWhere', [$root->snapshot(), new QueryExpression('callback', 'zeroOr', [$scope], type: 'callback')], $root->model, 'query', $root->singular));
            }

            if ($receiver->kind === 'request' && $method === 'validated' && $node->args === []) {
                return new QueryExpression('validated', $receiver->value, type: $receiver->type);
            }

            if ($receiver->type === 'query' && $receiver->model instanceof ModelMetadata && ($scope = ScopeRegistry::local($receiver->model->class, $method)) instanceof ReflectionMethod) {
                $args = array_values(array_map(fn (Node\Arg $arg): QueryExpression => $this->expression($arg->value, $variables)->snapshot(), $node->getArgs()));
                $body = $this->scope($scope, $receiver->model, $args);

                return $this->mutate($receiver, new QueryExpression('scope', $method, [$receiver->snapshot(), $body, ...$args], $receiver->model, 'query', $receiver->singular));
            }

            $operations = $receiver->type === 'builder' ? ['cmp', 'cmpLit', 'and', 'or', 'not', 'exists'] : ['where', 'orWhere', 'whereExists', 'whereHas', 'whereDoesntHave', 'with', 'orderBy', 'limit', 'start', 'one', 'all', 'infinite', 'paginate', 'withAttributes', 'withoutGlobalScope', 'withoutGlobalScopes', 'visibleTo'];
            if (in_array($receiver->kind, ['infinite', 'paginate'], true)) {
                $this->unsupported($node, 'Pagination must be the final query operation.');
            }

            if (! $receiver->model instanceof ModelMetadata || ! in_array($receiver->type, ['query', 'builder'], true) || ! in_array($method, $operations, true)) {
                $this->unsupported($node, 'Unrecognized query operation.');
            }

            $arguments = [];
            foreach ($node->getArgs() as $index => $argument) {
                if ($argument->unpack || $argument->name !== null && ! ($method === 'withAttributes' && $index === 1 && $argument->name->name === 'asConditions') || $argument->byRef) {
                    $this->unsupported($argument, 'Only positional query arguments are supported.');
                }

                if ($argument->value instanceof Expr\ArrowFunction || $argument->value instanceof Expr\Closure) {
                    $model = $receiver->model;
                    $type = 'query';
                    if ($index === 1 && in_array($method, ['with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true)) {
                        $relation = $arguments[0] ?? null;
                        if ($relation?->kind !== 'literal' || ! is_string($relation->value)) {
                            $this->unsupported($argument, 'Relationship callback requires a registered relationship.');
                        }

                        $model = $this->models->get($this->models->relation($model, $relation->value)['model']);
                        $type = 'query';
                    } elseif ($index !== 0 || ! in_array($method, ['where', 'orWhere'], true)) {
                        $this->unsupported($argument, 'Callback is not supported in this argument position.');
                    }

                    $callback = $argument->value;
                    $parameter = $callback->params[0] ?? null;
                    if (count($callback->params) !== 1 || $parameter === null || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name) || $parameter->byRef || $parameter->variadic || $parameter->default !== null || $callback->byRef) {
                        $this->unsupported($callback, 'Callbacks require one ordinary parameter.');
                    }

                    $name = $parameter->var->name;
                    $symbol = 'zero'.++$this->callbackId;
                    $local = QueryExpression::reference(new QueryExpression('local', $symbol, model: $model, type: $type));
                    if ($callback instanceof Expr\ArrowFunction) {
                        $body = $this->expression($callback->expr, [...$variables, $name => $local])->snapshot();
                    } else {
                        foreach ($callback->uses as $use) {
                            if ($use->byRef) {
                                $this->unsupported($use, 'Callback captures must be by value.');
                            }
                        }

                        $stmts = $callback->stmts;
                        $return = end($stmts);
                        if ($return instanceof Stmt\Return_) {
                            array_pop($stmts);
                        }

                        $locals = $this->statements($stmts, [...$variables, $name => $local]);
                        $body = $return instanceof Stmt\Return_ && $return->expr instanceof Expr ? $this->expression($return->expr, $locals)->snapshot() : $locals[$name]->snapshot();
                    }

                    if ($body->type !== 'query' || $body->model !== $model) {
                        $this->unsupported($callback, 'Callback must return a condition or a refinement of its related query.');
                    }

                    if (! $this->derivesFrom($body, $symbol)) {
                        $this->unsupported($callback, 'Callback must derive its result from the supplied builder.');
                    }

                    $expectedParameter = ZeroQuery::class;
                    if (($parameter->type !== null && (! $parameter->type instanceof Name || $parameter->type->toString() !== $expectedParameter)) || ($callback->returnType instanceof Node && (! $callback->returnType instanceof Identifier || $callback->returnType->name !== 'void') && (! $callback->returnType instanceof Name || $callback->returnType->toString() !== ZeroQuery::class))) {
                        $this->unsupported($callback, 'Callback annotations must match the supplied builder and return type.');
                    }

                    $arguments[] = new QueryExpression('callback', $symbol, [$body], type: 'callback');

                    continue;
                }

                $arguments[] = $this->expression($argument->value, $variables);
            }

            if (in_array($method, ['whereExists', 'exists'], true) && count($arguments) >= 2 && $arguments[1]->type === 'null') {
                $symbol = 'zero'.++$this->callbackId;
                $local = new QueryExpression('local', $symbol, type: 'query');
                $arguments[1] = new QueryExpression('callback', $symbol, [$local], type: 'callback');
            }

            if (in_array($method, ['where', 'orWhere', 'cmp', 'orderBy', 'with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true) && (! in_array($method, ['where', 'orWhere'], true) || ($arguments[0]->kind ?? null) !== 'callback') && ($method !== 'with' || ($arguments[0]->kind ?? null) !== 'object')) {
                $field = $arguments[0] ?? null;
                if (in_array($method, ['with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true) && $field?->kind === 'literal' && is_string($field->value)) {
                    $this->models->relation($receiver->model, $field->value);
                }

                $names = in_array($method, ['with', 'whereHas', 'whereDoesntHave', 'whereExists', 'exists'], true) ? $receiver->model->relations : $receiver->model->columns;
                if ($field?->kind !== 'literal' || ! is_string($field->value) || ! array_key_exists($field->value, $names)) {
                    $this->unsupported($node, 'Query fields and relationships must name a registered column or relationship.');
                }
            }

            if (in_array($method, ['where', 'orWhere', 'cmp'], true) && count($arguments) >= 2) {
                $last = count($arguments) - 1;
                if (in_array($arguments[$last]->type, ['date', 'dateOnly'], true)) {
                    $column = is_string($arguments[0]->value) ? $receiver->model->columns[$arguments[0]->value] ?? null : null;
                    if ($column === null || ! $column['date']) {
                        $this->unsupported($node, 'Date arguments require a date-cast column.');
                    }

                    $arguments[$last] = new QueryExpression('dateOperand', $column['dateFormat'] ?? null, [$arguments[$last]], type: $column['type'] === 'string' ? 'string' : 'int');
                }
            }

            $this->validateOperation($node, $receiver, $arguments);

            if (in_array($method, ['infinite', 'paginate'], true)) {
                $size = $arguments[0]->value;
                if ($receiver->singular || $receiver->hasBounds() || $arguments[0]->kind !== 'literal' || ! is_int($size) || $size < 1 || $size >= 2147483647) {
                    $this->unsupported($node, 'Pagination requires an unbounded collection and a literal positive page size.');
                }

                if (! $receiver->ordersKey()) {
                    $this->mutate($receiver, new QueryExpression('method', 'orderBy', [$receiver->snapshot(), new QueryExpression('literal', $receiver->queryModel()->key, type: 'string'), new QueryExpression('literal', 'asc', type: 'string')], $receiver->model, 'query'));
                }

                return $this->mutate($receiver, new QueryExpression($method, $size, [$receiver->snapshot()], $receiver->model, 'query'));
            }

            return $this->mutate($receiver, new QueryExpression('method', $method, [$receiver->snapshot(), ...$arguments], $receiver->model, $receiver->type === 'builder' ? 'condition' : 'query', $receiver->singular || $method === 'one'));
        }

        if ($node instanceof Expr\BooleanNot) {
            $operand = $this->expression($node->expr, $variables);
            if (! $this->boolean($operand)) {
                $this->unsupported($node, 'Negation requires a boolean operand.');
            }

            return new QueryExpression('not', children: [$operand], type: 'bool');
        }

        if ($node instanceof Expr\BinaryOp) {
            $operator = match (true) {
                $node instanceof Expr\BinaryOp\Identical => '===',
                $node instanceof Expr\BinaryOp\NotIdentical => '!==',
                $node instanceof Expr\BinaryOp\BooleanAnd => '&&',
                $node instanceof Expr\BinaryOp\BooleanOr => '||',
                $node instanceof Expr\BinaryOp\Concat => '.',
                default => null,
            };
            if ($operator !== null) {
                $left = $this->expression($node->left, $variables);
                $right = $this->expression($node->right, $operator === '&&' ? $this->narrow($left, $variables) : $variables);
                if ($left->model instanceof ModelMetadata || $right->model instanceof ModelMetadata) {
                    $this->unsupported($node, 'Scalar expressions cannot compare or coerce query objects.');
                }

                if ($operator === '.' && ($left->type !== 'string' || $right->type !== 'string')) {
                    $this->unsupported($node, 'Concatenation requires strings without implicit coercion.');
                }

                if ($left->type === 'optional' || $right->type === 'optional') {
                    $this->unsupported($node, 'Guard optional or nullable fields with isset before using them.');
                }

                if (in_array($operator, ['&&', '||'], true) && (! $this->boolean($left) || ! $this->boolean($right))) {
                    $this->unsupported($node, 'Boolean operands must not depend on implicit PHP coercion.');
                }

                return new QueryExpression('binary', $operator, [$left, $right], type: $operator === '.' ? 'string' : 'bool');
            }
        }

        $this->unsupported($node, 'Unsupported query dependency '.$node->getType().'.');
    }

    /** @param array<string, QueryExpression> $variables
     * @return array<string, QueryExpression>
     */
    private function narrow(QueryExpression $condition, array $variables): array
    {
        if ($condition->kind === 'isset') {
            $field = $condition->children[0];
            $variables["\0".$field->children[0]->emit(false).'.'.$field->value] = $field;
        } elseif ($condition->kind === 'binary' && $condition->value === '&&') {
            $variables = $this->narrow($condition->children[1], $this->narrow($condition->children[0], $variables));
        }

        return $variables;
    }

    private function boolean(QueryExpression $expression): bool
    {
        return $expression->type === 'bool';
    }

    /** @param list<QueryExpression> $arguments */
    private function validateOperation(Expr\MethodCall $node, QueryExpression $receiver, array $arguments): void
    {
        if (! $node->name instanceof Identifier) {
            $this->unsupported($node, 'Query methods require literal names.');
        }

        $method = $node->name->name;
        $count = count($arguments);
        $arity = match ($method) {
            'one', 'all' => $count === 0,
            'withoutGlobalScope' => $count === 1 && $arguments[0]->kind === 'literal' && $arguments[0]->type === 'string',
            'withoutGlobalScopes' => $count === 0 || $count === 1 && ($arguments[0]->kind === 'list' || $arguments[0]->type === 'null'),
            'withAttributes' => $count >= 1 && $count <= 2 && $arguments[0]->type === 'object' && ($count === 1 || $arguments[1]->kind === 'literal' && $arguments[1]->type === 'bool'),
            'limit', 'not', 'infinite', 'paginate', 'visibleTo' => $count === 1,
            'orderBy' => $count === 2,
            'with', 'start', 'whereHas', 'whereDoesntHave' => $count >= 1 && $count <= 2,
            'whereExists', 'exists' => $count >= 1 && $count <= 3,
            'where', 'orWhere' => ($arguments[0]->type ?? null) === 'callback' ? $count === 1 : ($count === 2 || $count === 3),
            'cmp' => $count === 2 || $count === 3,
            'cmpLit' => $count === 3,
            'and', 'or' => true,
            default => false,
        };
        if (! $arity) {
            $this->unsupported($node, "Invalid argument count for {$method}.");
        }

        if ($method === 'visibleTo' && $arguments[0]->kind !== 'actor') {
            $this->unsupported($node, 'visibleTo requires the trusted request user, never an editable query argument.');
        }

        if (in_array($method, ['and', 'or', 'not'], true)) {
            foreach ($arguments as $argument) {
                if ($argument->type !== 'condition' || $argument->model !== $receiver->model) {
                    $this->unsupported($node, 'Boolean query operations require conditions for the current model.');
                }
            }
        }

        if ($method === 'limit' && ($arguments[0]->type !== 'int' || ($arguments[0]->kind === 'literal' && ($arguments[0]->value < 0 || $arguments[0]->value > 2147483647)))) {
            $this->unsupported($node, 'Limit requires a non-negative signed 32-bit integer.');
        }

        if ($method === 'orderBy') {
            $direction = $arguments[1];
            $choices = $direction->kind === 'enum' ? $direction->children : [$direction];
            if (! in_array($direction->kind, ['literal', 'enum'], true) || $choices === [] || array_filter($choices, fn (QueryExpression $choice): bool => ! in_array($choice->value, ['asc', 'desc'], true)) !== []) {
                $this->unsupported($node, 'Order direction requires asc/desc literals or a backed enum containing only those values.');
            }
        }

        if (in_array($method, ['with', 'whereHas', 'whereDoesntHave'], true) && $count === 2 && $arguments[1]->type !== 'callback') {
            $this->unsupported($node, 'Relationship refinement requires a query callback.');
        }

        if (in_array($method, ['whereExists', 'exists'], true) && $count > 1) {
            $refinement = $arguments[1];
            $options = $arguments[2] ?? ($refinement->type === 'object' && $method === 'whereExists' ? $refinement : null);
            if ($refinement !== $options && ! in_array($refinement->type, ['callback', 'null'], true)) {
                $this->unsupported($node, 'Existence refinement requires a query callback or null.');
            }

            if ($options !== null) {
                if ($options->type !== 'object') {
                    $this->unsupported($node, 'Existence options require an object.');
                }

                foreach ($options->children as $property) {
                    if (! in_array($property->value, ['flip', 'scalar'], true) || $property->children[0]->type !== 'bool') {
                        $this->unsupported($node, 'Existence options support boolean flip and scalar values only.');
                    }
                }
            }
        }

        if ($method === 'start') {
            if ($arguments[0]->type !== 'object') {
                $this->unsupported($node, 'Cursor requires a literal field-keyed object.');
            }

            foreach ($arguments[0]->children as $property) {
                if (! is_string($property->value) || ! isset($receiver->model?->columns[$property->value])) {
                    $this->unsupported($node, 'Cursor must name registered columns.');
                }
            }

            if ($count === 2) {
                if ($arguments[1]->type !== 'object') {
                    $this->unsupported($node, 'Cursor options require an object.');
                }

                foreach ($arguments[1]->children as $property) {
                    if ($property->value !== 'inclusive' || $property->children[0]->type !== 'bool') {
                        $this->unsupported($node, 'Cursor options only support a boolean inclusive value.');
                    }
                }
            }
        }

        if ($method === 'withAttributes') {
            foreach ($arguments[0]->children as $property) {
                if (! in_array($property->value, $receiver->queryModel()->writable, true)) {
                    $this->unsupported($node, 'Creation defaults require an exposed, fillable, non-metadata column.');
                }
            }
        }

        if (in_array($method, ['where', 'orWhere', 'cmp', 'cmpLit'], true) && $count >= 2) {
            $operator = $count === 2 ? '=' : $arguments[1]->value;
            if (($count === 3 && $arguments[1]->kind !== 'literal') || ! in_array($operator, ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'], true)) {
                $this->unsupported($node, 'Comparison requires a supported literal operator.');
            }

            $value = $arguments[$count - 1];
            if (in_array($operator, ['IN', 'NOT IN'], true) ? $value->type !== 'list' : ! in_array($value->type, ['string', 'int', 'float', 'bool', 'null'], true)) {
                $this->unsupported($node, 'Comparison operand must be a scalar, or a list for membership.');
            }
        }
    }

    private function unsupported(Node $node, string $message): never
    {
        throw new LogicException("{$this->file}:{$node->getStartLine()} {$this->action} prop {$this->prop}: {$message}");
    }
}
