<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;

/**
 * Lowers checked Laravel construction to native ZQL, including deferred scopes.
 *
 * @phpstan-import-type RelationWhere from \ZeroForLaravel\Metadata\ModelMetadata
 */
final class NativeQueryEmitter
{
    private int $depth = 0;

    public function emit(QueryExpression $query): string
    {
        if (++$this->depth > 8) {
            throw new LogicException('Recursive Zero scope/relationship expansion exceeds eight levels at '.$query->model?->name.'.');
        }

        try {
            $query = $this->distribute($query->snapshot());
            if ($query->kind === 'conditional') {
                return '('.$query->children[0]->emit(true).' ? '.$this->emit($query->children[1]).' : '.$this->emit($query->children[2]).')';
            }

            [$root, $steps] = $this->steps($query);
            $base = match ($root->kind) {
                'query' => 'zql['.json_encode($root->queryModel()->table, JSON_THROW_ON_ERROR).']',
                'local' => $root->stringValue(),
                'bound' => 'zql['.json_encode($root->queryModel()->table, JSON_THROW_ON_ERROR).'].where('.json_encode($root->queryModel()->key, JSON_THROW_ON_ERROR).', args['.json_encode($root->value, JSON_THROW_ON_ERROR).']).one()',
                default => throw new LogicException('Unsupported native query root '.$root->kind),
            };

            $conditions = [];
            $removed = ScopeCompiler::removals($query);
            $scopes = [];
            if ($removed !== null) {
                foreach (ScopeCompiler::globals($query->queryModel()) as $name => $scope) {
                    if (! in_array($name, $removed, true)) {
                        $conditions[] = $this->condition($scope);
                        $scopes[] = $scope;
                    }
                }
            }

            $conditions[] = $this->condition($query);
            $conditions = array_values(array_filter($conditions, fn (string $condition): bool => $condition !== 'eb.and()'));
            if ($conditions !== []) {
                $base .= '.where(eb => '.(count($conditions) === 1 ? $conditions[0] : 'eb.and('.implode(', ', $conditions).')').')';
            }

            foreach ($scopes as $scope) {
                [, $scopeSteps] = $this->steps($scope);
                array_push($steps, ...$scopeSteps);
            }

            foreach ($steps as $step) {
                $args = array_slice($step->children, 1);
                if ($step->kind === 'scope') {
                    [, $body] = $this->steps($step->children[1]);
                    $base = $this->structural($base, $body);
                } elseif ($step->kind === 'infinite') {
                    $base .= '.limit(args.__window ?? '.((int) $step->value + 1).')';
                } elseif ($step->kind === 'paginate') {
                    $base .= '.limit('.((int) $step->value + 1).')';
                    $base = '(args.__cursor === undefined ? '.$base.' : '.$base.'.start(args.__cursor))';
                } elseif ($step->value === 'with') {
                    $base .= $this->include($step);
                } elseif (in_array($step->value, ['orderBy', 'limit', 'start', 'one'], true)) {
                    $base .= '.'.$step->value.'('.implode(', ', array_map(fn (QueryExpression $arg): string => $arg->emit(true), $args)).')';
                }
            }

            return $base;
        } finally {
            $this->depth--;
        }
    }

    public function condition(QueryExpression $query): string
    {
        $query = $this->distribute($query);
        if ($query->kind === 'conditional') {
            return '('.$query->children[0]->emit(true).' ? '.$this->condition($query->children[1]).' : '.$this->condition($query->children[2]).')';
        }

        [, $steps] = $this->steps($query);
        $groups = [[]];
        foreach ($steps as $step) {
            $added = null;
            $boolean = 'and';
            $args = array_slice($step->children, 1);
            if ($step->kind === 'scope') {
                $added = $this->condition($step->children[1]);
            } elseif (in_array($step->value, ['where', 'orWhere'], true)) {
                $boolean = $step->value === 'orWhere' ? 'or' : 'and';
                if ($args === []) {
                    throw new LogicException('A predicate requires operands.');
                }

                $added = $args[0]->kind === 'callback' ? $this->condition($args[0]->children[0]) : $this->comparison($args);
            } elseif ($step->value === 'withAttributes' && ($args[1]->value ?? true)) {
                $parts = [];
                foreach ($args[0]->children as $property) {
                    $parts[] = $this->comparison([ScopeCompiler::value($property->value), $property->children[0]]);
                }

                $added = 'eb.and('.implode(', ', $parts).')';
            } elseif (in_array($step->value, ['whereHas', 'whereDoesntHave', 'whereExists'], true)) {
                [$symbol, $child] = $this->child($step, $args[0]->stringValue());
                $option = $args[2] ?? (($args[1]->type ?? null) === 'object' ? $args[1] : null);
                $options = $option === null ? '' : ', '.$option->emit(true);
                $added = 'eb.exists('.$args[0]->emit(true).', '.$symbol.' => '.$this->emit($child).$options.')';
                if ($step->value === 'whereDoesntHave') {
                    $added = 'eb.not('.$added.')';
                }
            }

            if ($added !== null && $added !== 'eb.and()') {
                if ($boolean === 'or' && end($groups) !== []) {
                    $groups[] = [];
                }

                $groups[array_key_last($groups)][] = $added;
            }
        }

        $terms = array_map(fn (array $parts): string => count($parts) === 1 ? $parts[0] : 'eb.and('.implode(', ', $parts).')', $groups);

        return count($terms) === 1 ? $terms[0] : 'eb.or('.implode(', ', $terms).')';
    }

    /** @param list<QueryExpression> $steps */
    private function structural(string $base, array $steps): string
    {
        foreach ($steps as $step) {
            if ($step->kind === 'scope') {
                [, $body] = $this->steps($step->children[1]);
                $base = $this->structural($base, $body);
            } elseif ($step->value === 'with') {
                $base .= $this->include($step);
            } elseif (in_array($step->value, ['orderBy', 'limit', 'start', 'one'], true)) {
                $base .= '.'.$step->value.'('.implode(', ', array_map(fn (QueryExpression $arg): string => $arg->emit(true), array_slice($step->children, 1))).')';
            }
        }

        return $base;
    }

    private function include(QueryExpression $step): string
    {
        $name = $step->children[1]->stringValue();
        [$symbol, $child] = $this->child($step, $name);

        return '.related('.json_encode($name, JSON_THROW_ON_ERROR).', '.$symbol.' => '.$this->emit($child).')';
    }

    /** @return array{string, QueryExpression} */
    private function child(QueryExpression $step, string $name): array
    {
        $model = $step->queryModel();
        $relation = $model->registry->relation($model, $name);
        $target = $model->registry->get($relation['model']);
        $callback = $step->children[2] ?? null;
        $symbol = $callback?->kind === 'callback' ? $callback->stringValue() : 'zeroRelated';
        $local = new QueryExpression('local', $symbol, model: $target, type: 'query');
        $base = $this->relationConditions($local, $model->relationConstraints[$name]['wheres'] ?? []);
        foreach ($model->relationConstraints[$name]['orders'] ?? [] as $order) {
            $base = new QueryExpression('method', 'orderBy', [$base, ScopeCompiler::value(ModelMetadata::column($order['column'])), ScopeCompiler::value($order['direction'])], $target, 'query');
        }

        $body = $callback?->kind === 'callback' ? $this->replaceRoot($callback->children[0], $base) : $base;

        return [$symbol, $body];
    }

    /** @param list<RelationWhere> $wheres */
    private function relationConditions(QueryExpression $base, array $wheres): QueryExpression
    {
        foreach ($wheres as $where) {
            $args = $where['type'] === 'Nested'
                ? [new QueryExpression('callback', 'zeroNested', [$this->relationConditions(new QueryExpression('local', 'zeroNested', model: $base->model, type: 'query'), ModelRegistry::relationWheres($where['wheres'], $base->queryModel(), normalized: true))], type: 'callback')]
                : [ScopeCompiler::value($where['column']), ScopeCompiler::value($where['operator']), ScopeCompiler::value($where['value'])];
            $base = new QueryExpression('method', $where['boolean'] === 'or' ? 'orWhere' : 'where', [$base, ...$args], $base->model, 'query');
        }

        return $base;
    }

    private function replaceRoot(QueryExpression $query, QueryExpression $root): QueryExpression
    {
        if ($query->kind === 'local') {
            return $root;
        }

        $children = $query->children;
        if ($query->kind === 'conditional') {
            $children[1] = $this->replaceRoot($children[1], $root);
            $children[2] = $this->replaceRoot($children[2], $root);
        } else {
            $children[0] = $this->replaceRoot($children[0], $root);
        }

        return new QueryExpression($query->kind, $query->value, array_values($children), $query->model, $query->type, $query->singular);
    }

    /** @param non-empty-list<QueryExpression> $args */
    private function comparison(array $args): string
    {
        $field = $args[0]->emit(true);
        $operator = count($args) === 2 ? '=' : $args[1]->value;
        $operand = $args[array_key_last($args)];
        $value = $operand->emit(true);
        if (in_array($operator, ['=', '!='], true) && $operand->type === 'null') {
            $operator = $operator === '=' ? 'IS' : 'IS NOT';
        }

        $operator = in_array($operator, ['=', '!='], true) && $operand->kind === 'context' && ContextDefinition::configured()->fields[$operand->stringValue()]['nullable']
            ? '('.$value.' === null ? '.json_encode($operator === '=' ? 'IS' : 'IS NOT', JSON_THROW_ON_ERROR).' : '.json_encode($operator, JSON_THROW_ON_ERROR).')'
            : json_encode($operator, JSON_THROW_ON_ERROR);

        return 'eb.cmp('.$field.', '.$operator.', '.$value.')';
    }

    /** @return array{QueryExpression, list<QueryExpression>} */
    private function steps(QueryExpression $query): array
    {
        $steps = [];
        while (in_array($query->kind, ['method', 'scope', 'infinite', 'paginate', 'guarded'], true)) {
            array_unshift($steps, $query);
            $query = $query->children[0];
        }

        return [$query, $steps];
    }

    private function distribute(QueryExpression $query): QueryExpression
    {
        if (in_array($query->kind, ['method', 'scope', 'infinite', 'paginate', 'guarded'], true)) {
            $receiver = $this->distribute($query->children[0]);
            if ($receiver->kind === 'conditional') {
                $yes = clone $query;
                $no = clone $query;
                $yes->children[0] = $receiver->children[1];
                $no->children[0] = $receiver->children[2];

                return new QueryExpression('conditional', children: [$receiver->children[0], $this->distribute($yes), $this->distribute($no)], model: $query->model, type: 'query', singular: $query->singular);
            }
        }

        return $query;
    }
}
