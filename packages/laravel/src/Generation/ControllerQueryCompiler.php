<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
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
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\QueryInput;

/** Pure declarations only: an unrepresented guard or dependency is a build error. */
final class ControllerQueryCompiler
{
    private string $file = '';

    private string $action = '';

    private string $prop = '*';

    private int $callbackId = 0;

    public function __construct(private readonly Parser $parser, private readonly ModelRegistry $models) {}

    /** @return list<ControllerQuery> */
    public function compile(string $controller, string $method): array
    {
        $reflection = new ReflectionMethod($controller, $method);
        $this->file = $reflection->getFileName() ?: throw new LogicException('Controller source is unavailable.');
        $this->action = ltrim($controller, '\\').'@'.$method;
        $this->prop = '*';
        $this->callbackId = 0;
        $source = file_get_contents($this->file);
        if ($source === false) {
            throw new LogicException("Cannot read controller {$this->file}.");
        }

        $nodes = (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($source) ?? []);
        $node = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Stmt\ClassMethod && $node->name->name === $method && $node->getEndLine() === $reflection->getEndLine());
        if (! $node instanceof Stmt\ClassMethod || $node->stmts === null) {
            throw new LogicException("{$this->file}: {$this->action}: controller body is unavailable.");
        }

        $declaration = (new NodeFinder)->findFirst($node->stmts, fn (Node $candidate): bool => $candidate instanceof Expr\StaticCall && $candidate->name instanceof Identifier && $candidate->name->name === 'zeroQuery');
        $boundModels = array_filter($reflection->getParameters(), fn (ReflectionParameter $parameter): bool => $parameter->getType() instanceof ReflectionNamedType && is_subclass_of($parameter->getType()->getName(), Model::class));
        $render = (new NodeFinder)->findFirst($node->stmts, fn (Node $candidate): bool => $candidate instanceof Expr\StaticCall && $candidate->class instanceof Name && $candidate->class->toString() === \Inertia\Inertia::class && $candidate->name instanceof Identifier && $candidate->name->name === 'render');
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
            $matches = array_values(array_filter($this->models->all(), fn (\ZeroForLaravel\Metadata\ModelMetadata $model): bool => $model->class === $type));
            $model = $matches[0] ?? null;
            if ($model !== null) {
                $parameters[$name] = 'model:'.$type;
                $variables[$name] = new QueryExpression('bound', $name, model: $model, type: 'query', singular: true);
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
        if (! $return instanceof Stmt\Return_ || ! $return->expr instanceof Expr\StaticCall || ! $return->expr->class instanceof Name || $return->expr->class->toString() !== \Inertia\Inertia::class || ! $return->expr->name instanceof Identifier || $return->expr->name->name !== 'render') {
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
            'zero' => '1.11.3',
            'models' => array_map(fn (\ZeroForLaravel\Metadata\ModelMetadata $model): array => $model->toArray(), $models),
            'pivots' => $this->models->pivotColumns(),
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
            $expression = $this->expression($item->value, $variables);
            $props[$this->prop] = [$expression, $item->getStartLine()];
            if (! $expression->model instanceof \ZeroForLaravel\Metadata\ModelMetadata) {
                $ordinary[$this->prop] = match ($expression->type) {
                    'string' => 'string', 'int' => 'number', 'bool' => 'boolean', 'null' => 'null',
                    default => $this->unsupported($item, 'Ordinary prop requires a supported inferred contract.'),
                };
            }
        }

        $queries = [];
        foreach ($props as $prop => [$expression, $line]) {
            if ($expression->model instanceof \ZeroForLaravel\Metadata\ModelMetadata) {
                $queries[] = new ControllerQuery($this->action, $args[0]->value->value, $prop, $parameters, $expression, $this->file, $line, $schemaFingerprint, $ordinary);
            }
        }

        return $queries;
    }

    /**
     * @param  array<Stmt>  $statements
     * @param  array<string, QueryExpression>  $variables
     * @return array<string, QueryExpression>
     */
    private function statements(array $statements, array $variables): array
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression && $statement->expr instanceof Expr\Assign && $statement->expr->var instanceof Expr\Variable && is_string($statement->expr->var->name)) {
                $variables[$statement->expr->var->name] = $this->expression($statement->expr->expr, $variables);
            } elseif ($statement instanceof Stmt\If_ && $statement->elseifs === []) {
                $condition = $this->expression($statement->cond, $variables);
                if (! $this->boolean($condition)) {
                    $this->unsupported($statement->cond, 'A portable condition must be explicitly boolean, not PHP truthiness.');
                }

                $yes = $this->statements($statement->stmts, $this->narrow($condition, $variables));
                $no = $this->statements($statement->else->stmts ?? [], $variables);
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

                    $variables[$name] = $yes[$name] === $no[$name] ? $yes[$name] : new QueryExpression('conditional', children: [$condition, $yes[$name], $no[$name]], model: $yes[$name]->model, type: $yes[$name]->type, singular: $yes[$name]->singular);
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
        if ($node instanceof Scalar\String_ || $node instanceof Scalar\Int_) {
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
                return new QueryExpression('literal', $case->value, type: get_debug_type($case->value));
            }
        }

        if ($node instanceof Expr\Array_) {
            $items = [];
            $object = false;
            foreach ($node->items as $item) {
                if ($item === null || ($item->key !== null && ! $item->key instanceof Scalar\String_) || $item->unpack || $item->byRef) {
                    $this->unsupported($node, 'Arrays must be scalar lists or literal string-keyed objects.');
                }

                $value = $this->expression($item->value, $variables);
                if (! in_array($value->type, ['string', 'int', 'bool', 'null'], true)) {
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

        if ($node instanceof Expr\StaticCall && $node->class instanceof Name && $node->name instanceof Identifier && $node->name->name === 'zeroQuery' && $node->args === []) {
            foreach ($this->models->all() as $model) {
                if ($model->class === $node->class->toString()) {
                    return new QueryExpression('query', model: $model, type: 'query');
                }
            }

            $this->unsupported($node, 'Query model is not registered for Zero.');
        }

        if ($node instanceof Expr\MethodCall && $node->name instanceof Identifier) {
            $receiver = $this->expression($node->var, $variables);
            $method = $node->name->name;
            if ($receiver->kind === 'request' && $method === 'validated' && $node->args === []) {
                return new QueryExpression('validated', $receiver->value, type: $receiver->type);
            }

            $operations = $receiver->type === 'builder' ? ['cmp', 'cmpLit', 'and', 'or', 'not', 'exists'] : ['where', 'whereExists', 'related', 'orderBy', 'limit', 'start', 'one', 'all', 'infinite', 'paginate'];
            if (in_array($receiver->kind, ['infinite', 'paginate'], true)) {
                $this->unsupported($node, 'Pagination must be the final query operation.');
            }

            if (! $receiver->model instanceof \ZeroForLaravel\Metadata\ModelMetadata || ! in_array($receiver->type, ['query', 'builder'], true) || ! in_array($method, $operations, true)) {
                $this->unsupported($node, 'Unrecognized query operation.');
            }

            $arguments = [];
            foreach ($node->getArgs() as $index => $argument) {
                if ($argument->unpack || $argument->name !== null || $argument->byRef) {
                    $this->unsupported($argument, 'Only positional query arguments are supported.');
                }

                if ($argument->value instanceof Expr\ArrowFunction) {
                    $model = $receiver->model;
                    $type = 'builder';
                    if ($index === 1 && in_array($method, ['related', 'whereExists', 'exists'], true)) {
                        $relation = $arguments[0] ?? null;
                        if ($relation?->kind !== 'literal' || ! is_string($relation->value) || ! isset($model->relations[$relation->value])) {
                            $this->unsupported($argument, 'Relationship callback requires a registered relationship.');
                        }

                        $model = $this->models->get($model->relations[$relation->value]['model']);
                        $type = 'query';
                    } elseif ($index !== 0 || $method !== 'where') {
                        $this->unsupported($argument, 'Callback is not supported in this argument position.');
                    }

                    $callback = $argument->value;
                    $parameter = $callback->params[0] ?? null;
                    if (count($callback->params) !== 1 || $parameter === null || ! $parameter->var instanceof Expr\Variable || ! is_string($parameter->var->name) || $parameter->byRef || $parameter->variadic || $parameter->default !== null || $callback->byRef) {
                        $this->unsupported($callback, 'Callbacks require one ordinary parameter.');
                    }

                    $name = $parameter->var->name;
                    $symbol = 'zero'.++$this->callbackId;
                    $local = new QueryExpression('local', $symbol, model: $model, type: $type);
                    $body = $this->expression($callback->expr, [...$variables, $name => $local]);
                    if ($body->type !== ($type === 'builder' ? 'condition' : 'query') || $body->model !== $model) {
                        $this->unsupported($callback, 'Callback must return a condition or a refinement of its related query.');
                    }

                    $root = $body;
                    while ($root->kind === 'method') {
                        $root = $root->children[0];
                    }

                    if ($root !== $local) {
                        $this->unsupported($callback, 'Callback must derive its result from the supplied builder.');
                    }

                    $expectedParameter = $type === 'builder' ? \ZeroForLaravel\Query\ExpressionBuilder::class : \ZeroForLaravel\Query\Query::class;
                    $expectedReturn = $type === 'builder' ? \ZeroForLaravel\Query\Condition::class : \ZeroForLaravel\Query\Query::class;
                    if (($parameter->type !== null && (! $parameter->type instanceof Name || $parameter->type->toString() !== $expectedParameter)) || ($callback->returnType instanceof Node && (! $callback->returnType instanceof Name || $callback->returnType->toString() !== $expectedReturn))) {
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

            if (in_array($method, ['where', 'cmp', 'orderBy', 'related', 'whereExists', 'exists'], true) && ($method !== 'where' || ($arguments[0]->kind ?? null) !== 'callback')) {
                $field = $arguments[0] ?? null;
                $names = in_array($method, ['related', 'whereExists', 'exists'], true) ? $receiver->model->relations : $receiver->model->columns;
                if ($field?->kind !== 'literal' || ! is_string($field->value) || ! array_key_exists($field->value, $names)) {
                    $this->unsupported($node, 'Query fields and relationships must name a registered column or relationship.');
                }
            }

            if (in_array($method, ['where', 'cmp'], true) && count($arguments) >= 2) {
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
                    $receiver = new QueryExpression('method', 'orderBy', [$receiver, new QueryExpression('literal', $receiver->model->key, type: 'string'), new QueryExpression('literal', 'asc', type: 'string')], $receiver->model, 'query');
                }

                return new QueryExpression($method, $size, [$receiver], $receiver->model, 'query');
            }

            return new QueryExpression('method', $method, [$receiver, ...$arguments], $receiver->model, $receiver->type === 'builder' ? 'condition' : 'query', $receiver->singular || $method === 'one');
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
                if ($left->model instanceof \ZeroForLaravel\Metadata\ModelMetadata || $right->model instanceof \ZeroForLaravel\Metadata\ModelMetadata) {
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
            'limit', 'not', 'infinite', 'paginate' => $count === 1,
            'orderBy' => $count === 2,
            'related', 'start' => $count >= 1 && $count <= 2,
            'whereExists', 'exists' => $count >= 1 && $count <= 3,
            'where' => ($arguments[0]->type ?? null) === 'callback' ? $count === 1 : ($count === 2 || $count === 3),
            'cmp' => $count === 2 || $count === 3,
            'cmpLit' => $count === 3,
            'and', 'or' => true,
            default => false,
        };
        if (! $arity) {
            $this->unsupported($node, "Invalid argument count for {$method}.");
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

        if ($method === 'related' && $count === 2 && $arguments[1]->type !== 'callback') {
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

        if (in_array($method, ['where', 'cmp', 'cmpLit'], true) && $count >= 2) {
            $operator = $count === 2 ? '=' : $arguments[1]->value;
            if (($count === 3 && $arguments[1]->kind !== 'literal') || ! in_array($operator, ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'], true)) {
                $this->unsupported($node, 'Comparison requires a supported literal operator.');
            }

            $value = $arguments[$count - 1];
            if (in_array($operator, ['IN', 'NOT IN'], true) ? $value->type !== 'list' : ! in_array($value->type, ['string', 'int', 'bool', 'null'], true)) {
                $this->unsupported($node, 'Comparison operand must be a scalar, or a list for membership.');
            }
        }
    }

    private function unsupported(Node $node, string $message): never
    {
        throw new LogicException("{$this->file}:{$node->getStartLine()} {$this->action} prop {$this->prop}: {$message}");
    }
}
