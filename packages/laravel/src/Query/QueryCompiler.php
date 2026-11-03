<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Illuminate\Auth\Access\AuthorizationException;
use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\ZeroVisibility;
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
    /** @param list<array{class: class-string<\Illuminate\Database\Eloquent\Model>, id: string, ability: string}> $guards */
    public function __construct(private ModelRegistry $registry, private array $guards = [], private bool $serving = false) {}

    /** Compile an application-owned declaration, never an AST supplied over HTTP.
     * @param  list<array{class: class-string<\Illuminate\Database\Eloquent\Model>, id: string, ability: string}>  $guards
     * @return array<string, mixed>
     */
    public function compileDeclaration(Query $query, ZeroContext $context, array $guards = [], bool $serving = false): array
    {
        return (new self($this->registry, $guards, $serving))->authorizeDeclaration($query->ast(), $context, 0);
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

            $ability = $ast['__ability'] ?? 'view';
            if (! is_string($ability)) {
                throw new LogicException('Invalid captured policy ability.');
            }

            $conditions = $this->visibility($model, $context, $ast['__visibility'] ?? null, $ability);
            unset($ast['__visibility'], $ast['__ability']);
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

    /** @return list<WireCondition> */
    private function visibility(ModelMetadata $model, ZeroContext $context, mixed $subjectId = null, string $ability = 'view'): array
    {
        $conditions = [];
        if ($this->guards !== [] && ! $model->policyVisibility) {
            throw new LogicException('Captured guards on public query paths cannot be maintained live. Bind a portable permission resource.');
        }

        if ($model->policyVisibility) {
            if ($this->serving) {
                $projector = new \ZeroForLaravel\Permissions\ProjectionManager;
                $projector->assertConnection(new $model->class);
                $projector->assertReady();
            }

            if ($subjectId === null) {
                throw new LogicException("{$model->name}: protected query paths require visibleTo or a captured instance Gate.");
            }

            if (! is_string($subjectId) || $context->userId() === null || $subjectId !== $context->userId()) {
                throw new AuthorizationException('Visibility actor differs from the authenticated Zero identity.');
            }

            $conditions[] = (new ZeroVisibility)->condition($model, $subjectId, $ability, $this->guards)->toArray();
        }

        if ($model->deletedAt !== null) {
            $conditions[] = $this->comparison($model->deletedAt, 'IS', null);
        }

        return $conditions;
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
