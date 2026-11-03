<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Laravel\Surveyor\Analyzed\ClassLikeResult;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\StringType;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use ReflectionMethod;
use ZeroForLaravel\Generation\Mutations\ValueContract;

/** Static request shapes; Laravel still executes all authoritative rules and hooks. */
final class RequestContract
{
    /** @return array<string, list<string>> */
    public static function rules(string $class): array
    {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new LogicException('Expected a FormRequest contract.');
        }

        $type = self::literalRules($class);
        if (! $type instanceof ArrayType) {
            $analyzer = resolve(Analyzer::class);
            $analyzer->analyzeClass($class);
            $result = $analyzer->result();
            $type = $result instanceof ClassLikeResult ? $result->getMethod('rules')?->returnType() : null;
        }

        if (! $type instanceof ArrayType) {
            throw new LogicException($class.': rules() must have a statically known field shape.');
        }

        $choices = RequestChoices::forRequest($class);
        $fields = [];
        foreach ($type->value as $field => $rules) {
            if (! is_string($field)) {
                throw new LogicException($class.': request fields must have string names.');
            }

            $items = $rules instanceof ArrayType ? $rules->value : [$rules];
            $fields[$field] = [];
            foreach ($items as $rule) {
                if ($rule instanceof StringType && $rule->value !== null) {
                    array_push($fields[$field], ...explode('|', $rule->value));
                }
            }

            if (isset($choices[$field])) {
                if ($choices[$field] === []) {
                    throw new LogicException('Finite input choices cannot be empty.');
                }

                $fields[$field][] = 'choices:'.json_encode($choices[$field], JSON_THROW_ON_ERROR);
                $fields[$field][] = is_int($choices[$field][0]) ? 'integer' : 'string';
            }

            self::type($fields[$field]);
        }

        return $fields;
    }

    public static function value(string $class): ValueContract
    {
        $rules = self::rules($class);
        $field = function (string $path) use (&$field, $rules): ValueContract {
            $constraints = $rules[$path] ?? throw new LogicException('Missing validation contract for '.$path);
            $kind = self::type($constraints);
            $fields = [];
            $members = [];
            if ($kind === 'array') {
                if (isset($rules[$path.'.*'])) {
                    if (! in_array('list', $constraints, true)) {
                        throw new LogicException($path.': repeated inputs require the Laravel list rule.');
                    }

                    $kind = 'list';
                    $members[] = $field($path.'.*');
                } else {
                    $kind = 'object';
                    foreach (array_keys($rules) as $key) {
                        if (str_starts_with($key, $path.'.') && ! str_contains(substr($key, strlen($path) + 1), '.')) {
                            $fields[substr($key, strlen($path) + 1)] = $field($key);
                        }
                    }

                    if ($fields === []) {
                        throw new LogicException($path.': arrays require statically declared child rules.');
                    }
                }
            }

            return new ValueContract($kind, fields: $fields, members: $members,
                optional: in_array('sometimes', $constraints, true) || (! in_array('required', $constraints, true) && ! in_array('present', $constraints, true)),
                nullable: in_array('nullable', $constraints, true), rules: $constraints);
        };
        $fields = [];
        foreach (array_keys($rules) as $key) {
            if (! str_contains($key, '.')) {
                $fields[$key] = $field($key);
            }
        }

        return new ValueContract('object', fields: $fields);
    }

    /** @param list<string> $rules */
    public static function type(array $rules): string
    {
        foreach (['date_format:Y-m-d' => 'dateOnly', 'date' => 'date', 'string' => 'string', 'uuid' => 'string', 'ulid' => 'string', 'integer' => 'int', 'numeric' => 'number', 'boolean' => 'bool', 'array' => 'array'] as $rule => $type) {
            if (in_array($rule, $rules, true)) {
                return $type;
            }
        }

        throw new LogicException('Request field requires an explicit supported scalar validation type.');
    }

    /** Read ordinary literal rules without analyzing unrelated request hooks and their dependencies. */
    private static function literalRules(string $class): ?ArrayType
    {
        $reflection = new ReflectionMethod($class, 'rules');
        $source = file_get_contents($reflection->getFileName() ?: '') ?: '';
        $nodes = (new NodeTraverser(new NameResolver))->traverse(resolve(Parser::class)->parse($source) ?? []);
        $method = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->getStartLine() === $reflection->getStartLine());
        if (! $method instanceof Node\Stmt\ClassMethod || count($method->stmts ?? []) !== 1
            || ! $method->stmts[0] instanceof Node\Stmt\Return_ || ! $method->stmts[0]->expr instanceof Node\Expr\Array_) {
            return null;
        }

        $fields = [];
        foreach ($method->stmts[0]->expr->items as $field) {
            if ($field === null || $field->unpack || ! $field->key instanceof Node\Scalar\String_) {
                return null;
            }

            $rules = $field->value instanceof Node\Expr\Array_ ? $field->value->items : [new Node\ArrayItem($field->value)];
            $values = [];
            foreach ($rules as $rule) {
                if ($rule === null || $rule->unpack) {
                    return null;
                }

                if ($rule->value instanceof Node\Scalar\String_) {
                    $values[] = new StringType($rule->value->value);
                } elseif (! $rule->value instanceof Node\Expr\StaticCall || ! $rule->value->class instanceof Node\Name
                    || $rule->value->class->toString() !== Rule::class
                    || ! $rule->value->name instanceof Node\Identifier || ! in_array($rule->value->name->name, ['enum', 'in'], true)) {
                    return null;
                }

                // RequestChoices reads finite rules independently, without executing them.
            }

            $fields[$field->key->value] = new ArrayType($values);
        }

        return new ArrayType($fields);
    }
}
