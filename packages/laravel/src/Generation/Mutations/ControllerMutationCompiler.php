<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use Illuminate\Foundation\Http\FormRequest;
use LogicException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use PhpParser\PrettyPrinter\Standard;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Attributes\ZeroShared;
use ZeroForLaravel\Generation\RequestContract;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\Mutation;

/**
 * Closed subset: every source statement must have an explicit operation.
 *
 * @phpstan-import-type VariableType from MutationProgram
 * @phpstan-import-type Expression from MutationProgram
 */
final class ControllerMutationCompiler
{
    /** @var array<string,string> */
    private array $sites = [];

    /** @var list<string> */
    private array $guards = [];

    /** @var list<string> */
    private array $stack = [];

    /** @var array<string,string> */
    private array $sources = [];

    private string $location = '';

    private bool $generated = true;

    private bool $inLoop = false;

    private bool $hasLoops = false;

    public function __construct(private readonly Parser $parser, private readonly ModelRegistry $models) {}

    public function compile(string $controller, string $method): MutationProgram
    {
        $reflection = new ReflectionMethod($controller, $method);
        $attribute = $reflection->getAttributes(ZeroMutation::class)[0] ?? throw new LogicException('Mutation action must opt in with #[ZeroMutation].');
        $config = $attribute->newInstance();
        $this->generated = $config->optimism === 'generated';
        $this->sites = [];
        $this->guards = [];
        $this->stack = [];
        $this->sources = [];
        $this->inLoop = false;
        $this->hasLoops = false;
        [$node, $parameters] = $this->method($controller, $method);
        $return = $reflection->getReturnType();
        if (! $return instanceof ReflectionNamedType || ! in_array($return->getName(), ['void', 'array', 'string', 'int', 'float', 'bool', 'null', 'mixed'], true) || ($return->allowsNull() && ! in_array($return->getName(), ['null', 'mixed'], true))) {
            $this->fail($node, 'Declare void or an explicit JSON return type.');
        }

        $variables = $parameters;
        foreach ($parameters as $parameter) {
            if ($this->generated && $parameter['kind'] === 'scalar' && ($parameter['type'] ?? '') !== 'string') {
                $this->fail($node, 'Controller route scalars currently require string parameters.');
            }
        }

        $operations = $config->optimism === 'generated' ? $this->statements($node->stmts ?? [], $variables) : [];
        $this->validateSeeds($node);
        $data = [$controller, $method, $config, $parameters, $operations, $this->sources,
            array_map(fn (ModelMetadata $model): array => $model->toArray(), $this->models->all())];

        return new MutationProgram($controller.'@'.$method, $config->optimism, $config->conceal,
            $return->getName() !== 'void', $parameters, $operations, $this->sites, $this->guards,
            hash('sha256', serialize($data)), $return->getName(), $config->serverResult, $config->clientResult);
    }

    /** @return array{Stmt\ClassMethod, array<string,VariableType>} */
    private function method(string $class, string $method): array
    {
        $reflection = new ReflectionMethod($class, $method);
        if (! $reflection->isPublic() || $reflection->isStatic()) {
            throw new LogicException('Shared actions must be public instance methods.');
        }

        $file = $reflection->getFileName();
        if ($file === false) {
            throw new LogicException('Controller source is unavailable.');
        }

        $this->location = $file.': '.$class.'@'.$method;
        $source = file_get_contents($file);
        if ($source === false) {
            throw new LogicException('Cannot read controller source '.$file);
        }

        $this->sources[$class] = hash('sha256', $source);
        $nodes = (new NodeTraverser(new NameResolver))->traverse($this->parser->parse($source) ?? []);
        $node = (new NodeFinder)->findFirst($nodes, fn (Node $n): bool => $n instanceof Stmt\ClassMethod && $n->name->name === $method && $n->getEndLine() === $reflection->getEndLine());
        if (! $node instanceof Stmt\ClassMethod || $node->stmts === null) {
            throw new LogicException($this->location.': Missing method body.');
        }

        $parameters = [];
        foreach ($node->params as $param) {
            if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name) || $param->byRef || $param->variadic || $param->default !== null || ! $param->type instanceof Node\Name && ! $param->type instanceof Node\Identifier) {
                $this->fail($param, 'Required named typed parameters only.');
            }

            $type = $param->type->toString();
            if (class_exists($type)) {
                $dependency = (new ReflectionClass($type))->getFileName();
                if ($dependency !== false) {
                    $this->sources[$type] = hash_file('sha256', $dependency) ?: throw new LogicException('Cannot hash dependency '.$dependency);
                }
            }

            $kind = match (true) {
                is_subclass_of($type, FormRequest::class) => 'request',
                $type === Mutation::class => 'context',
                isset($this->modelClasses()[$type]) => 'model',
                in_array($type, ['string', 'int', 'bool', 'array'], true) => 'scalar',
                class_exists($type) && (new ReflectionClass($type))->getAttributes(ZeroShared::class) !== [] => 'helper',
                ! $this->generated && (class_exists($type) || interface_exists($type)) => 'service',
                default => $this->fail($param, 'Unsupported parameter dependency '.$type),
            };
            $parameters[$param->var->name] = ['kind' => $kind, 'type' => $type];
        }

        return [$node, $parameters];
    }

    /**
     * @param  array<Stmt>  $statements
     * @param  array<string,VariableType>  $variables
     * @return list<array<string,mixed>>
     */
    private function statements(array $statements, array &$variables): array
    {
        $operations = [];
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Expression) {
                $expression = $statement->expr;
                if ($expression instanceof Expr\Assign && $expression->var instanceof Expr\Variable && is_string($expression->var->name)) {
                    $value = $this->expression($expression->expr, $variables);
                    $variables[$expression->var->name] = $value['result'] ?? ['kind' => 'value'];
                    $operations[] = ['op' => 'set', 'name' => $expression->var->name, 'value' => $value];
                } elseif ($expression instanceof Expr\StaticCall && $expression->class instanceof Node\Name && $this->guard($expression)) {
                    if ($expression->class->toString() === Mutation::class) {
                        $closure = $expression->args[0]->value ?? null;
                        if (! $closure instanceof Expr\Closure || count($expression->args) !== 1 || $closure->byRef || $closure->params !== []) {
                            $this->fail($expression, 'Server-only boundary requires a void closure without parameters.');
                        }

                        foreach ($closure->uses as $use) {
                            if ($use->byRef || ! is_string($use->var->name) || ! isset($variables[$use->var->name])) {
                                $this->fail($use, 'Server-only captures must be known by-value locals.');
                            }
                        }

                        if ((new NodeFinder)->findFirst($closure, fn (Node $n): bool => $n instanceof Expr\Variable && $n->name === 'this') instanceof Node) {
                            $this->fail($closure, 'Server-only closures cannot capture the controller instance.');
                        }

                        // Conservatively invalidate object aliases and transaction dependencies.
                        // A server-only write can affect them even without capturing a local.
                        foreach ($variables as $name => $type) {
                            if (in_array($type['kind'], ['model', 'request', 'helper', 'context'], true)) {
                                unset($variables[$name]);
                            }
                        }
                    }

                    $index = count($this->guards);
                    // Server-only expressions cannot export a value or alter shared control flow.
                    $this->guards[] = 'static function (array $__scope): void { extract($__scope, EXTR_SKIP); '.(new Standard)->prettyPrintExpr($expression).'; }';
                    $operations[] = ['op' => 'guard', 'index' => $index];
                } else {
                    $operations[] = ['op' => 'expression', 'value' => $this->expression($expression, $variables)];
                }
            } elseif ($statement instanceof Stmt\If_ && $statement->elseifs === []) {
                $condition = $this->expression($statement->cond, $variables);
                $yes = $variables;
                $no = $variables;
                $then = $this->statements($statement->stmts, $yes);
                $else = $this->statements($statement->else->stmts ?? [], $no);
                // Only locals definitely assigned on both paths can escape a branch.
                $variables = array_intersect_key($yes, $no);
                foreach ($variables as $key => $type) {
                    if ($type !== $no[$key]) {
                        $this->fail($statement, 'Branch changes variable type '.$key);
                    }
                }

                $operations[] = ['op' => 'if', 'condition' => $condition, 'then' => $then, 'else' => $else];
            } elseif ($statement instanceof Stmt\Foreach_) {
                if ($this->inLoop || $statement->byRef || $statement->keyVar instanceof Expr || ! $statement->valueVar instanceof Expr\Variable || ! is_string($statement->valueVar->name)) {
                    $this->fail($statement, 'Use one bounded foreach over validated items, by value without a positional key.');
                }

                $value = $this->expression($statement->expr, $variables);
                $shape = $this->inputShape($value['result'] ?? []);
                $key = $shape?->members[0]->fields['key'] ?? null;
                $maximum = 0;
                foreach ($shape->rules ?? [] as $rule) {
                    if (str_starts_with($rule, 'max:') && ctype_digit(substr($rule, 4))) {
                        $maximum = (int) substr($rule, 4);
                    }
                }

                if ($shape?->kind !== 'list' || $shape->optional || $shape->nullable || $key?->kind !== 'string' || $key->optional || $key->nullable || $maximum < 1 || $maximum > 1000) {
                    $this->fail($statement, 'Repeated items require a present nonnull list, max:1..1000, and a required string key field.');
                }

                $bodyVariables = $variables;
                $bodyVariables[$statement->valueVar->name] = ['kind' => 'input', 'type' => $value['result']['type'] ?? '', 'path' => ($value['result']['path'] ?? '').'.*'];
                $this->hasLoops = true;
                $this->inLoop = true;
                $body = $this->statements($statement->stmts, $bodyVariables);
                $this->inLoop = false;
                foreach ($variables as $name => $type) {
                    if (($bodyVariables[$name] ?? null) !== $type) {
                        $this->fail($statement, 'A loop cannot change an outer local contract.');
                    }
                }

                $operations[] = ['op' => 'foreach', 'value' => $value, 'name' => $statement->valueVar->name, 'max' => $maximum, 'body' => $body];
            } elseif ($statement instanceof Stmt\Return_) {
                $operations[] = ['op' => 'return', 'value' => $statement->expr instanceof Expr ? $this->expression($statement->expr, $variables) : ['op' => 'literal', 'value' => null]];
            } else {
                $this->fail($statement, 'Unsupported statement in the shared mutation subset.');
            }
        }

        return $operations;
    }

    /**
     * @param  array<string,VariableType>  $variables
     * @return Expression
     */
    private function expression(Expr $expr, array $variables): array
    {
        $e = fn (Expr $node): array => $this->expression($node, $variables);
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\Int_ || $expr instanceof Node\Scalar\Float_) {
            return ['op' => 'literal', 'value' => $expr->value];
        }

        if ($expr instanceof Expr\ConstFetch && in_array(strtolower($expr->name->toString()), ['true', 'false', 'null'], true)) {
            return ['op' => 'literal', 'value' => match (strtolower($expr->name->toString())) {
                'true' => true, 'false' => false, default => null
            }];
        }

        if ($expr instanceof Expr\Variable && is_string($expr->name) && isset($variables[$expr->name])) {
            return ['op' => 'local', 'name' => $expr->name, 'result' => $variables[$expr->name]];
        }

        if ($expr instanceof Expr\ArrayDimFetch && $expr->dim instanceof Expr) {
            $base = $e($expr->var);
            $node = ['op' => 'get', 'base' => $base, 'key' => $e($expr->dim)];
            if (($base['result']['kind'] ?? '') === 'input' && $expr->dim instanceof Node\Scalar\String_) {
                $node['result'] = ['kind' => 'input', 'type' => $base['result']['type'] ?? '', 'path' => ltrim(($base['result']['path'] ?? '').'.'.$expr->dim->value, '.')];
            }

            return $node;
        }

        if ($expr instanceof Expr\PropertyFetch && $expr->name instanceof Node\Identifier) {
            $base = $e($expr->var);
            $meta = $this->modelClasses()[$base['result']['type'] ?? ''] ?? null;
            if ($meta === null || ! isset($meta->columns[$expr->name->name])) {
                $this->fail($expr, 'Only registered model columns are portable properties.');
            }

            $column = $meta->columns[$expr->name->name];
            if ($column['date'] || $column['enum'] !== null || ($column['cast'] ?? null) === 'object') {
                $this->fail($expr, 'Object-valued casts require an explicit portable codec.');
            }

            $model = new $meta->class;
            $cast = explode(':', $model->getCasts()[$expr->name->name] ?? '')[0];
            if ($model->hasGetMutator($expr->name->name) || $model->hasAttributeGetMutator($expr->name->name) || ($cast !== '' && ! in_array($cast, ['int', 'integer', 'bool', 'boolean', 'float', 'double', 'real', 'string', 'array', 'json'], true))) {
                $this->fail($expr, 'Custom model accessors/casts require an explicit projection.');
            }

            return ['op' => 'get', 'base' => $base, 'key' => ['op' => 'literal', 'value' => $expr->name->name], 'result' => ['kind' => 'value', 'type' => $column['type'], 'nullable' => $column['nullable']]];
        }

        if ($expr instanceof Expr\Array_) {
            $items = [];
            $list = true;
            foreach ($expr->items as $item) {
                if ($item === null || $item->unpack || $item->byRef || ($item->key !== null && ! $item->key instanceof Node\Scalar\String_)) {
                    $this->fail($expr, 'Only dense lists or literal string-keyed objects are portable.');
                }

                if ($item->key instanceof Node\Scalar\String_) {
                    if (is_numeric($item->key->value)) {
                        $this->fail($expr, 'Numeric object keys require an explicit JSON shape contract.');
                    }

                    $list = false;
                }

                $items[] = ['key' => $item->key?->value, 'value' => $e($item->value)];
            }

            if (! $list && count(array_filter($items, fn (array $item): bool => $item['key'] === null))) {
                $this->fail($expr, 'Mixed list/object arrays are unsupported.');
            }

            return ['op' => $list ? 'list' : 'object', 'items' => $items];
        }

        if ($expr instanceof Expr\BinaryOp) {
            $operator = match (true) {
                $expr instanceof Expr\BinaryOp\Identical => '===', $expr instanceof Expr\BinaryOp\NotIdentical => '!==',
                $expr instanceof Expr\BinaryOp\BooleanAnd => '&&', $expr instanceof Expr\BinaryOp\BooleanOr => '||',
                default => $this->fail($expr, 'Only strict comparison and boolean operators are portable.'),
            };
            $left = $e($expr->left);
            $right = $e($expr->right);
            if (in_array($operator, ['===', '!=='], true)) {
                if (! $this->comparable($left) && ! $this->comparable($right)) {
                    $this->fail($expr, 'Strict comparison requires a known string, boolean, or null operand; numeric/array identity is not portable.');
                }
            }

            return ['op' => 'binary', 'operator' => $operator, 'left' => $left, 'right' => $right];
        }

        if ($expr instanceof Expr\BooleanNot) {
            return ['op' => 'not', 'value' => $e($expr->expr)];
        }

        if ($expr instanceof Expr\Isset_ && count($expr->vars) === 1) {
            return ['op' => 'isset', 'value' => $e($expr->vars[0])];
        }

        if ($expr instanceof Expr\FuncCall && $expr->name instanceof Node\Name && $expr->name->toString() === 'array_key_exists' && count($expr->args) === 2) {
            foreach ($expr->args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->name instanceof Node\Identifier || $arg->unpack) {
                    $this->fail($expr, 'Unsupported key check argument.');
                }
            }

            return ['op' => 'has', 'key' => $e($expr->args[0]->value), 'base' => $e($expr->args[1]->value)];
        }

        if ($expr instanceof Expr\StaticCall && $expr->class instanceof Node\Name && $expr->name instanceof Node\Identifier && $expr->name->name === 'create') {
            return $this->create($expr, $expr->class->toString(), $variables);
        }

        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            $base = $e($expr->var);
            $kind = $base['result']['kind'] ?? '';
            $method = $expr->name->name;
            if ($kind === 'context' && in_array($method, ['findUnique', 'insertUnlessExists', 'upsertUnique'], true)) {
                $names = $method === 'findUnique' ? ['class', 'unique'] : ['class', 'unique', 'data', 'key'];
                $args = [];
                foreach ($expr->args as $index => $arg) {
                    if (! $arg instanceof Node\Arg || $arg->unpack) {
                        $this->fail($expr, 'Unsupported unique operation argument.');
                    }

                    $name = $arg->name->name ?? $names[$index] ?? '';
                    if (! in_array($name, $names, true) || isset($args[$name])) {
                        $this->fail($expr, 'Invalid unique operation argument.');
                    }

                    $args[$name] = $arg->value;
                }

                if (count($args) !== count($names) || ! ($args['class'] ?? null) instanceof Expr\ClassConstFetch || ! $args['class']->class instanceof Node\Name || ! $args['class']->name instanceof Node\Identifier || $args['class']->name->name !== 'class' || ! ($args['unique'] ?? null) instanceof Expr\Array_) {
                    $this->fail($expr, 'Unique operations require Model::class and a literal unique-key map.');
                }

                $class = $args['class']->class->toString();
                $meta = $this->modelClasses()[$class] ?? null;
                if ($meta === null) {
                    $this->fail($expr, 'Unregistered unique operation model.');
                }

                $fields = [];
                foreach ($args['unique']->items as $item) {
                    if ($item === null || ! $item->key instanceof Node\Scalar\String_) {
                        $this->fail($expr, 'Unique keys must be literal column names.');
                    }

                    $fields[] = $item->key->value;
                }

                \ZeroForLaravel\Mutation\UniqueMutationKey::validate($meta, $fields, $method !== 'findUnique');
                $node = ['op' => $method, 'class' => $class, 'model' => $meta->name, 'unique' => $e($args['unique']), 'result' => ['kind' => 'model', 'type' => $class, 'nullable' => $method === 'findUnique']];
                if ($method !== 'findUnique') {
                    if (! ($args['key'] ?? null) instanceof Node\Scalar\String_ || ! ($args['data'] ?? null) instanceof Expr || $meta->idGenerator === null) {
                        $this->fail($expr, 'Unique writes require data, a stable literal creation key and UUID/ULID model.');
                    }

                    $site = 'key:'.$args['key']->value;
                    if (isset($this->sites[$site])) {
                        $this->fail($expr, 'Duplicate logical creation site.');
                    }

                    $this->sites[$site] = $meta->idGenerator;
                    $node['site'] = $site;
                    $node['data'] = $e($args['data']);
                }

                return $node;
            }

            if ($kind === 'context' && $method === 'create') {
                $args = [];
                foreach ($expr->args as $index => $arg) {
                    if (! $arg instanceof Node\Arg || $arg->unpack) {
                        $this->fail($expr, 'Create does not support unpacking.');
                    }

                    $key = $arg->name->name ?? ['class', 'data', 'key'][$index] ?? '';
                    if (isset($args[$key])) {
                        $this->fail($expr, 'Duplicate create argument.');
                    }

                    $args[$key] = $arg->value;
                }

                if (count($args) !== 3 || ! ($args['class'] ?? null) instanceof Expr\ClassConstFetch || ! $args['class']->class instanceof Node\Name || ! $args['class']->name instanceof Node\Identifier || $args['class']->name->toString() !== 'class' || ! ($args['key'] ?? null) instanceof Node\Scalar\String_ || ! isset($args['data'])) {
                    $this->fail($expr, 'Create requires Model::class, data, and a literal stable key.');
                }

                $class = $args['class']->class->toString();
                $meta = $this->modelClasses()[$class] ?? null;
                $site = 'key:'.$args['key']->value;
                if ($meta === null || $meta->idGenerator === null || isset($this->sites[$site])) {
                    $this->fail($expr, 'Create requires a UUID/ULID model and a unique logical site key.');
                }

                $this->sites[$site] = $meta->idGenerator;

                return ['op' => 'create', 'model' => $meta->name, 'class' => $class, 'site' => $site, 'data' => $e($args['data']), 'result' => ['kind' => 'model', 'type' => $class]];
            }

            foreach ($expr->args as $arg) {
                if (! $arg instanceof Node\Arg || $arg->unpack || $arg->name instanceof Node\Identifier) {
                    $this->fail($expr, 'Named/unpacked calls are not supported.');
                }
            }

            if ($kind === 'request' && $method === 'validated' && $expr->args === []) {
                return ['op' => 'validated', 'base' => $base, 'result' => ['kind' => 'input', 'type' => $base['result']['type'] ?? '', 'path' => '']];
            }

            if ($kind === 'context' && $method === 'timestamp' && $expr->args === []) {
                return ['op' => 'timestamp'];
            }

            if ($kind === 'context' && $method === 'reloadForUpdate' && count($expr->args) === 1) {
                $record = $e($expr->args[0]->value);
                if (($record['result']['kind'] ?? '') !== 'model') {
                    $this->fail($expr, 'Reload requires a bound model.');
                }

                return ['op' => 'reload', 'record' => $record, 'result' => $record['result']];
            }

            if (($kind === 'model' || $kind === 'context') && in_array($method, ['update', 'delete'], true)) {
                $args = array_map(fn (\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder|Node\ArgPlaceholder $arg): array => $e($arg->value), $expr->args);
                $record = $kind === 'model' ? $base : array_shift($args);
                if (($record['result']['kind'] ?? '') !== 'model' || count($args) !== ($method === 'update' ? 1 : 0)) {
                    $this->fail($expr, 'Invalid write arguments.');
                }

                $meta = $this->modelClasses()[$record['result']['type'] ?? ''] ?? throw new LogicException('Unknown compiled model.');
                if ($method === 'delete' && $meta->deletedAt !== null) {
                    $this->fail($expr, 'Soft delete is not portable yet.');
                }

                return ['op' => $method, 'record' => $record, 'data' => $args[0] ?? null];
            }

            if ($kind === 'helper') {
                $class = $base['result']['type'] ?? '';
                if (! class_exists($class)) {
                    $this->fail($expr, 'Unknown shared helper class.');
                }

                $id = $class.'@'.$method;
                if (in_array($id, $this->stack, true)) {
                    $this->fail($expr, 'Recursive shared call '.$id);
                }

                if ((new ReflectionClass($class))->getConstructor() instanceof ReflectionMethod) {
                    $this->fail($expr, 'Shared helper constructors are unsupported.');
                }

                $args = array_map(fn (\PhpParser\Node\Arg|\PhpParser\Node\VariadicPlaceholder|Node\ArgPlaceholder $arg): array => $e($arg->value), $expr->args);
                $location = $this->location;
                $this->stack[] = $id;
                [$node, $params] = $this->method($class, $method);
                if (count($params) !== count($args)) {
                    $this->fail($expr, 'Shared call argument count mismatch.');
                }

                $names = array_keys($params);
                $body = $this->statements($node->stmts ?? [], $params);
                array_pop($this->stack);
                $this->location = $location;

                return ['op' => 'call', 'parameters' => $names, 'args' => $args, 'body' => $body];
            }
        }

        $this->fail($expr, 'Unsupported expression '.$expr->getType().'; use an explicit server-only statement or custom/disabled optimism.');
    }

    /**
     * @param  array<string,VariableType>  $variables
     * @return Expression
     */
    private function create(Expr\StaticCall $expr, string $class, array $variables): array
    {
        $meta = $this->modelClasses()[$class] ?? null;
        if ($meta === null || $meta->idGenerator === null || count($expr->args) !== 1 || ! $expr->args[0] instanceof Node\Arg || $expr->args[0]->name instanceof Node\Identifier || $expr->args[0]->unpack) {
            $this->fail($expr, 'Generated create requires a registered UUID/ULID model and one data argument.');
        }

        $site = 's'.count($this->sites);
        $this->sites[$site] = $meta->idGenerator;

        return ['op' => 'create', 'model' => $meta->name, 'class' => $class, 'site' => $site,
            'data' => $this->expression($expr->args[0]->value, $variables), 'result' => ['kind' => 'model', 'type' => $class]];
    }

    /** @param Expression $value */
    private function comparable(array $value): bool
    {
        return ($value['op'] === 'literal' && (is_string($value['value'] ?? null) || is_bool($value['value'] ?? null) || ($value['value'] ?? null) === null)) || in_array($value['result']['type'] ?? '', ['string', 'boolean', 'bool'], true);
    }

    /** @param VariableType|array{} $type */
    private function inputShape(array $type): ?ValueContract
    {
        if (($type['kind'] ?? '') !== 'input') {
            return null;
        }

        $shape = RequestContract::value($type['type'] ?? '');
        foreach (explode('.', $type['path'] ?? '') as $part) {
            if ($part !== '') {
                $shape = ($part === '*' ? $shape->members[0] ?? null : $shape->fields[$part] ?? null);
                if ($shape === null) {
                    return null;
                }
            }
        }

        return $shape;
    }

    private function validateSeeds(Node $node): void
    {
        if ($this->hasLoops && in_array('ulid', $this->sites, true)) {
            $this->fail($node, 'Keyed repeated creation requires UUID models; ULID derivation is not supported.');
        }
    }

    private function guard(Expr $expr): bool
    {
        if (! $expr instanceof Expr\StaticCall || ! $expr->class instanceof Node\Name || ! $expr->name instanceof Node\Identifier) {
            return false;
        }

        return ($expr->class->toString() === \Illuminate\Support\Facades\Gate::class && $expr->name->name === 'authorize')
            || ($expr->class->toString() === Mutation::class && $expr->name->name === 'server');
    }

    /** @return array<string,ModelMetadata> */
    private function modelClasses(): array
    {
        $classes = [];
        foreach ($this->models->all() as $meta) {
            $classes[$meta->class] = $meta;
        }

        return $classes;
    }

    private function fail(Node $node, string $message): never
    {
        throw new LogicException($this->location.':'.$node->getStartLine().': '.$message);
    }
}
