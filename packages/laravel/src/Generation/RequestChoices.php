<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use BackedEnum;
use Illuminate\Validation\Rule;
use LogicException;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\Parser;
use ReflectionMethod;

/** Read finite rule arguments without executing application validation code. */
final class RequestChoices
{
    /** @return array<string, list<string|int>> */
    public static function forRequest(string $class): array
    {
        $reflection = new ReflectionMethod($class, 'rules');
        $file = $reflection->getFileName();
        if ($file === false) {
            return [];
        }

        $nodes = resolve(Parser::class)->parse(file_get_contents($file) ?: '') ?? [];
        $nodes = (new NodeTraverser(new NameResolver))->traverse($nodes);

        $method = (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod && $node->getStartLine() === $reflection->getStartLine());
        if (! $method instanceof Node\Stmt\ClassMethod) {
            return [];
        }

        $finiteRule = (new NodeFinder)->findFirst($method->stmts ?? [], fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === Rule::class && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['enum', 'in'], true));
        if ($finiteRule instanceof Node && (count($method->stmts ?? []) !== 1 || ! $method->stmts[0] instanceof Node\Stmt\Return_ || ! $method->stmts[0]->expr instanceof Node\Expr\Array_)) {
            throw new LogicException('Finite input choices require a direct literal rules array, not conditional rule construction.');
        }

        $choices = [];
        foreach ((new NodeFinder)->findInstanceOf($method->stmts ?? [], Node\Expr\ArrayItem::class) as $field) {
            if (! $field->key instanceof Node\Scalar\String_ || ! $field->value instanceof Node\Expr\Array_) {
                continue;
            }

            foreach ($field->value->items as $item) {
                $call = $item?->value;
                if ($call instanceof Node\Expr\MethodCall && (new NodeFinder)->findFirst([$call], fn (Node $node): bool => $node instanceof Node\Expr\StaticCall && $node->class instanceof Node\Name && $node->class->toString() === Rule::class && $node->name instanceof Node\Identifier && in_array($node->name->toString(), ['enum', 'in'], true)) instanceof Node) {
                    throw new LogicException('Chained finite validation rules are not supported; use an explicit static Rule::in list.');
                }

                if (! $call instanceof Node\Expr\StaticCall || ! $call->class instanceof Node\Name || $call->class->toString() !== Rule::class || ! $call->name instanceof Node\Identifier) {
                    continue;
                }

                $argument = $call->args[0] ?? null;
                if (! $argument instanceof Node\Arg) {
                    continue;
                }

                $values = null;
                if ($call->name->toString() === 'enum' && $argument->value instanceof Node\Expr\ClassConstFetch && $argument->value->class instanceof Node\Name && $argument->value->name instanceof Node\Identifier && $argument->value->name->toString() === 'class') {
                    $enum = $argument->value->class->toString();
                    if (! is_subclass_of($enum, BackedEnum::class)) {
                        throw new LogicException('Query choices require a backed enum.');
                    }

                    $values = array_map(fn (BackedEnum $case): string|int => $case->value, $enum::cases());
                } elseif ($call->name->toString() === 'in' && $argument->value instanceof Node\Expr\Array_) {
                    $values = [];
                    foreach ($argument->value->items as $choice) {
                        if ($choice === null || (! $choice->value instanceof Node\Scalar\String_ && ! $choice->value instanceof Node\Scalar\Int_)) {
                            throw new LogicException('Rule::in choices must be static strings or integers.');
                        }

                        $values[] = $choice->value->value;
                    }
                }

                if ($values !== null) {
                    $name = $field->key->value;
                    $choices[$name] = isset($choices[$name]) ? array_values(array_filter($choices[$name], fn (string|int $choice): bool => in_array($choice, $values, true))) : $values;
                }
            }
        }

        return $choices;
    }
}
