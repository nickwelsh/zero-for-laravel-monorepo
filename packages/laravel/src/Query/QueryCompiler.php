<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use LogicException;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Lower only application-owned construction; scope removal never enters the wire API. */
final readonly class QueryCompiler
{
    public function __construct(private ModelRegistry $registry) {}

    /** @return array<string, mixed> */
    public function compileDeclaration(ZeroQuery $query, ResolvedContext $context): array
    {
        return $this->compileAst(Input::object(ContextReference::resolve($query->ast(), $context), 'query'));
    }

    /** @param array<string, mixed> $ast
     * @return array<string, mixed>
     */
    private function compileAst(array $ast): array
    {
        $model = null;
        foreach ($this->registry->all() as $candidate) {
            if ($candidate->table === $ast['table']) {
                $model = $candidate;
                break;
            }
        }

        if ($model === null && (! is_string($ast['table']) || ! isset($this->registry->pivots()[$ast['table']]))) {
            throw new LogicException('Query references a table outside the registered Zero schema.');
        }

        $condition = function (mixed $node) use (&$condition, $model): array {
            $value = Input::object($node, 'condition');
            if (isset($value['related'])) {
                $related = Input::object($value['related'], 'related');
                $related['subquery'] = $this->compileAst(Input::object($related['subquery'], 'subquery'));
                $value['related'] = $related;
            } elseif (isset($value['conditions'])) {
                if (! is_array($value['conditions'])) {
                    throw new LogicException('Conditions must be an array.');
                }

                $value['conditions'] = array_map($condition, $value['conditions']);
            } elseif ($model !== null) {
                $left = Input::object($value['left'], 'left');
                $right = Input::object($value['right'], 'right');
                if ($left['type'] === 'column' && $right['type'] === 'literal') {
                    if (! is_string($left['name'])) {
                        throw new LogicException('Comparison column must be a string.');
                    }

                    $builder = new ExpressionBuilder($model, static fn (): never => throw new LogicException('Unexpected exists operand.'));
                    $value = $builder->cmp($left['name'], $value['op'], $right['value'])->toArray();
                }
            }

            return $value;
        };
        if (isset($ast['where'])) {
            $ast['where'] = $condition($ast['where']);
        }

        if (isset($ast['related'])) {
            if (! is_array($ast['related'])) {
                throw new LogicException('Related queries must be an array.');
            }

            foreach ($ast['related'] as $index => $node) {
                $related = Input::object($node, 'related');
                $related['subquery'] = $this->compileAst(Input::object($related['subquery'], 'subquery'));
                $ast['related'][$index] = $related;
            }
        }

        return $ast;
    }
}
