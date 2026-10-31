<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Support\Input;
use ZeroForLaravel\ZeroContext;

/**
 * @phpstan-type Simple array{type: 'simple', op: string, left: array{type: 'column', name: string}, right: array{type: 'literal', value: mixed}}
 * @phpstan-type WireCondition array<string, mixed>
 * @phpstan-type Ast array{table: string, where?: WireCondition, orderBy?: list<array{string, 'asc'|'desc'}>, limit?: int, alias?: string, related?: list<array<string, mixed>>}
 * @phpstan-type Related array{correlation: array{parentField: list<string>, childField: list<string>}, subquery: Ast, system: 'client'}
 */
final readonly class QueryCompiler
{
    public function __construct(private ModelRegistry $registry) {}

    /** Compile an application-owned declaration, never an AST supplied over HTTP.
     * @return array<string, mixed>
     */
    public function compileDeclaration(Query $query, ZeroContext $context): array
    {
        return $this->authorizeDeclaration($query->ast(), $context, 0);
    }

    /** @param array<string, mixed> $ast
     * @return array<string, mixed>
     */
    private function authorizeDeclaration(array $ast, ZeroContext $context, int $depth): array
    {
        if ($depth > 8) {
            Input::fail('query', 'Query relationships exceed the maximum depth.');
        }

        if (isset($ast['where'])) {
            $ast['where'] = $this->authorizeCondition(Input::object($ast['where'], 'condition'), $context, $depth);
        }

        if (isset($ast['related']) && is_array($ast['related'])) {
            foreach ($ast['related'] as $index => $related) {
                $related = Input::object($related, 'relationship');
                $related['subquery'] = $this->authorizeDeclaration(Input::object($related['subquery'], 'subquery'), $context, $depth + 1);
                $ast['related'][$index] = $related;
            }
        }

        foreach ($this->registry->all() as $model) {
            if ($model->table !== $ast['table']) {
                continue;
            }

            $conditions = $this->visibility($model, $context, $depth);
            if (isset($ast['where'])) {
                $conditions[] = Input::object($ast['where'], 'condition');
            }

            if ($conditions !== []) {
                $ast['where'] = $this->conjunction($conditions);
            }

            return $ast;
        }

        if (! is_string($ast['table']) || ! isset($this->registry->pivots()[$ast['table']])) {
            throw new LogicException('Query references a table outside the registered model schema.');
        }

        return $ast;
    }

    /** @param array<string, mixed> $condition
     * @return array<string, mixed>
     */
    private function authorizeCondition(array $condition, ZeroContext $context, int $depth): array
    {
        if ($condition['type'] === 'correlatedSubquery') {
            $related = Input::object($condition['related'], 'relationship');
            $related['subquery'] = $this->authorizeDeclaration(Input::object($related['subquery'], 'subquery'), $context, $depth + 1);
            $condition['related'] = $related;
        } elseif (isset($condition['conditions']) && is_array($condition['conditions'])) {
            foreach ($condition['conditions'] as $index => $child) {
                $condition['conditions'][$index] = $this->authorizeCondition(Input::object($child, 'condition'), $context, $depth);
            }
        }

        return $condition;
    }

    /** @return Related */
    private function related(ModelMetadata $model, string $name, ZeroContext $context, int $depth, ?Closure $refine = null): array
    {
        $relation = $model->relations[$name];
        $target = $this->registry->get($relation['model']);
        $path = $relation['path'];
        if ($depth > 8) {
            Input::fail('query', 'Query relationships exceed the maximum depth.');
        }

        $conditions = $this->visibility($target, $context, $depth);
        if ($refine instanceof Closure) {
            $query = $this->scopeBuilder($target, $context, $depth);
            $refine($query);
            $conditions[] = $query->condition();
        }

        $ast = ['table' => $target->table];
        if ($conditions !== []) {
            $ast['where'] = $this->conjunction($conditions);
        }

        $ast['alias'] = 'zsubq_'.$name;
        if (count($path) > 1) {
            $ast['alias'] = 'zsubq_zhidden_'.$name;
            $inner = ['correlation' => ['parentField' => $path[1]['source'], 'childField' => $path[1]['dest']], 'subquery' => $ast, 'system' => 'client'];
            $ast = ['table' => $path[0]['table'], 'alias' => 'zsubq_'.$name, 'where' => ['type' => 'correlatedSubquery', 'related' => $inner, 'op' => 'EXISTS']];
        }

        return ['correlation' => ['parentField' => $path[0]['source'], 'childField' => $path[0]['dest']], 'subquery' => $ast, 'system' => 'client'];
    }

    /** @return list<WireCondition> */
    private function visibility(ModelMetadata $model, ZeroContext $context, int $depth): array
    {
        $conditions = array_map(fn (array $rule): array => $this->comparison($rule['field'], $rule['op'], array_key_exists('context', $rule['value']) ? $context->get($rule['value']['context']) : $rule['value']['literal']), $model->authorization);
        foreach ($model->authorizationRelations as $relation) {
            $conditions[] = ['type' => 'correlatedSubquery', 'related' => $this->related($model, $relation, $context, $depth + 1), 'op' => 'EXISTS'];
        }

        foreach ($model->definition->serverScopes as $scope) {
            $query = $this->scopeBuilder($model, $context, $depth);
            $scope($query, $context);
            $conditions[] = $query->condition();
        }

        if ($model->deletedAt !== null) {
            $conditions[] = $this->comparison($model->deletedAt, 'IS', null);
        }

        return $conditions;
    }

    private function scopeBuilder(ModelMetadata $model, ZeroContext $context, int $depth): ZQLBuilder
    {
        return new ZQLBuilder(new ZeroOperator($model, function (string $name, ?Closure $refine) use ($model, $context, $depth): array {
            if (! isset($model->relations[$name])) {
                throw new LogicException("Register scope relation {$model->name}.{$name} using enforceServerScope relations.");
            }

            return $this->related($model, $name, $context, $depth + 1, $refine);
        }));
    }

    /** @return Simple */
    private function comparison(string $field, string $operator, mixed $value): array
    {
        return ['type' => 'simple', 'op' => $operator, 'left' => ['type' => 'column', 'name' => $field], 'right' => ['type' => 'literal', 'value' => $value]];
    }

    /** @param list<WireCondition> $conditions
     * @return WireCondition
     */
    private function conjunction(array $conditions): array
    {
        return count($conditions) === 1 ? $conditions[0] : ['type' => 'and', 'conditions' => $conditions];
    }
}
