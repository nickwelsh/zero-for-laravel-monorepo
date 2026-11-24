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

    /** Relationship membership is independent of any preview's ordering and limit. */
    public function relationMembership(ModelMetadata $parent, string $relation, QueryExpression $base): string
    {
        return $this->emit($base, $this->relationConditions($base, $parent->relationConstraints[$relation]['wheres'] ?? []));
    }

    public function emit(QueryExpression $query, ?QueryExpression $relationFilter = null, bool $modelRead = false): string
    {
        if (++$this->depth > 8) {
            throw new LogicException('Recursive Zero scope/relationship expansion exceeds eight levels at '.$query->model?->name.'.');
        }

        try {
            $query = $query->snapshot()->distribute();
            if ($query->kind === 'conditional') {
                return '('.$query->children[0]->emit(true).' ? '.$this->emit($query->children[1], $relationFilter, $modelRead).' : '.$this->emit($query->children[2], $relationFilter, $modelRead).')';
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

            if ($relationFilter instanceof QueryExpression) {
                $conditions[] = $this->condition($relationFilter);
            }

            $conditions[] = $this->condition($query);
            $conditions = array_values(array_filter($conditions, fn (string $condition): bool => $condition !== 'eb.and()'));
            if ($conditions !== []) {
                $base .= '.where(eb => '.(count($conditions) === 1 ? $conditions[0] : 'eb.and('.implode(', ', $conditions).')').')';
            }

            $cursor = $query->kind === 'paginate' && $query->value === 'cursor';
            foreach ($steps as $step) {
                $args = array_slice($step->children, 1);
                if ($step->kind === 'scope') {
                    [, $body] = $this->steps($step->children[1]);
                    $base = $this->structural($base, $body, $cursor, $modelRead);
                } elseif ($step->value === 'orderBy') {
                    $base .= $this->orderBy($step, $cursor);
                } elseif ($step->value === 'with') {
                    $base .= $this->include($step, $modelRead);
                } elseif ($step->value === 'first') {
                    $base .= '.one()';
                } elseif (in_array($step->value, ['orderBy', 'limit', 'start', 'one'], true)) {
                    $base .= '.'.$step->value.'('.implode(', ', array_map(fn (QueryExpression $arg): string => $arg->emit(true), $args)).')';
                }
            }

            foreach ($scopes as $scope) {
                [, $body] = $this->steps($scope);
                // Model reads already merged the global includes with the model's and caller's.
                $base = $this->structural($base, $body, $cursor, false, ! $modelRead);
            }

            if ($query->kind === 'paginate') {
                $key = $query->queryModel()->key;
                $ordered = $query->children[0]->cursorFields();
                foreach ($scopes as $scope) {
                    array_push($ordered, ...$scope->cursorFields());
                }

                if (! in_array($key, $ordered, true)) {
                    $base .= '.orderBy('.json_encode($key, JSON_THROW_ON_ERROR).', '.($cursor ? 'args.__reverse ? "desc" : "asc"' : '"asc"').')';
                }

                $size = $query->children[1]->emit(true);
                if ($cursor) {
                    $base .= '.limit('.$size.' + 1)';

                    return '(args.__cursor === undefined ? '.$base.' : '.$base.'.start(args.__cursor))';
                }

                $base .= '.limit(pagePrefix('.$size.', args.__page'.($query->value === 'scroll' ? ', args.__window' : '').'))';
            }

            return $base;
        } finally {
            $this->depth--;
        }
    }

    public function condition(QueryExpression $query): string
    {
        $query = $query->distribute();
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
            } elseif (in_array($step->value, ['whereIn', 'orWhereIn', 'whereNotIn', 'orWhereNotIn'], true)) {
                $boolean = str_starts_with($step->stringValue(), 'or') ? 'or' : 'and';
                $added = $this->membership($args[0], $args[1], str_contains($step->stringValue(), 'Not'));
            } elseif ($step->value === 'withAttributes' && ($args[1]->value ?? true)) {
                $parts = [];
                foreach ($args[0]->children as $property) {
                    $parts[] = $this->comparison([ScopeCompiler::value($property->value), $property->children[0]]);
                }

                $added = 'eb.and('.implode(', ', $parts).')';
            } elseif (in_array($step->value, ['whereHas', 'whereDoesntHave'], true)) {
                [$symbol, $child, $filter] = $this->child($step, $args[0]->stringValue());
                $added = 'eb.exists('.$args[0]->emit(true).', '.$symbol.' => '.$this->emit($child, $filter).')';
                if ($step->value === 'whereDoesntHave') {
                    $added = 'eb.not('.$added.')';
                }
            }

            if ($added !== null && $added !== 'eb.and()') {
                if ($step->kind === 'scope' && count($groups) > 1) {
                    // Eloquent groups the pre-scope disjunction separately from the new constraints.
                    $groups = [[$this->joinConditions($groups)]];
                }

                if ($boolean === 'or' && end($groups) !== []) {
                    $groups[] = [];
                }

                $groups[array_key_last($groups)][] = $added;
            }
        }

        return $this->joinConditions($groups);
    }

    /** @param list<list<string>> $groups */
    private function joinConditions(array $groups): string
    {
        $terms = array_map(fn (array $parts): string => count($parts) === 1 ? $parts[0] : 'eb.and('.implode(', ', $parts).')', $groups);

        return count($terms) === 1 ? $terms[0] : 'eb.or('.implode(', ', $terms).')';
    }

    /** @param list<QueryExpression> $steps */
    private function structural(string $base, array $steps, bool $cursor, bool $modelRead, bool $include = true): string
    {
        foreach ($steps as $step) {
            if ($step->kind === 'scope') {
                [, $body] = $this->steps($step->children[1]);
                $base = $this->structural($base, $body, $cursor, $modelRead, $include);
            } elseif ($step->value === 'orderBy') {
                $base .= $this->orderBy($step, $cursor);
            } elseif ($step->value === 'with' && $include) {
                $base .= $this->include($step, $modelRead);
            } elseif (in_array($step->value, ['orderBy', 'limit', 'start', 'one'], true)) {
                $base .= '.'.$step->value.'('.implode(', ', array_map(fn (QueryExpression $arg): string => $arg->emit(true), array_slice($step->children, 1))).')';
            }
        }

        return $base;
    }

    private function orderBy(QueryExpression $step, bool $cursor): string
    {
        $direction = $step->children[2]->emit(true);
        if ($cursor) {
            $reverse = match ($direction) {
                '"asc"' => '"desc"', '"desc"' => '"asc"', default => '('.$direction.' === "asc" ? "desc" : "asc")',
            };
            $direction = 'args.__reverse ? '.$reverse.' : '.$direction;
        }

        return '.orderBy('.$step->children[1]->emit(true).', '.$direction.')';
    }

    private function include(QueryExpression $step, bool $modelRead): string
    {
        $step = $step->firstRelationHop();
        $name = $step->children[1]->stringValue();
        [$symbol, $child, $filter] = $this->child($step, $name);

        return '.related('.json_encode($name, JSON_THROW_ON_ERROR).', '.$symbol.' => '.$this->emit($child, $filter, $modelRead).')';
    }

    /** @return array{string, QueryExpression, QueryExpression|null} */
    private function child(QueryExpression $step, string $name): array
    {
        $model = $step->queryModel();
        $relation = $model->registry->relation($model, $name);
        $target = $model->registry->get($relation['model']);
        $callback = $step->children[2] ?? null;
        $symbol = $callback?->kind === 'callback' ? $callback->stringValue() : 'zeroRelated';
        $local = new QueryExpression('local', $symbol, model: $target, type: 'query');
        $wheres = $model->relationConstraints[$name]['wheres'] ?? [];
        $filter = $wheres === [] ? null : $this->relationConditions($local, $wheres);
        $base = $local;
        foreach ($model->relationConstraints[$name]['orders'] ?? [] as $order) {
            $base = new QueryExpression('method', 'orderBy', [$base, ScopeCompiler::value(ModelMetadata::column($order['column'])), ScopeCompiler::value($order['direction'])], $target, 'query');
        }

        $body = $callback?->kind === 'callback' ? $this->replaceRoot($callback->children[0], $base) : $base;

        return [$symbol, $body, $filter];
    }

    /** @param list<RelationWhere> $wheres */
    private function relationConditions(QueryExpression $base, array $wheres): QueryExpression
    {
        foreach ($wheres as $where) {
            if ($where['type'] === 'Nested') {
                $args = [new QueryExpression('callback', 'zeroNested', [$this->relationConditions(new QueryExpression('local', 'zeroNested', model: $base->model, type: 'query'), ModelRegistry::relationWheres($where['wheres'], $base->queryModel(), normalized: true))], type: 'callback')];
            } else {
                $column = $base->queryModel()->columns[$where['column']];
                $operand = $column['date'] ? ScopeCompiler::dateValue($where['value'], $column['dateFormat'] ?? null) : ScopeCompiler::value($where['value']);
                $args = [ScopeCompiler::value($where['column']), ScopeCompiler::value($where['operator']), $operand];
            }

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

    private function membership(QueryExpression $field, QueryExpression $values, bool $not): string
    {
        $nonNull = array_values(array_filter($values->children, static fn (QueryExpression $v): bool => $v->type !== 'null'));
        if ($nonNull === [] || $not && count($nonNull) !== count($values->children)) {
            return 'eb.cmpLit(1, "=", '.($not && $values->children === [] ? '1' : '0').')';
        }

        return 'eb.cmp('.$field->emit(true).', "'.($not ? 'NOT IN' : 'IN').'", '.(new QueryExpression('list', children: $nonNull, type: 'list'))->emit(true).')';
    }

    /** @param non-empty-list<QueryExpression> $args */
    private function comparison(array $args): string
    {
        $field = $args[0]->emit(true);
        $operator = count($args) === 2 ? '=' : $args[1]->value;
        if ($operator === '<>') {
            $operator = '!='; // Reflected Eloquent relationship constraints retain their SQL spelling.
        }

        $operand = $args[array_key_last($args)];
        if (in_array($operator, ['IN', 'NOT IN'], true)) {
            return $this->membership($args[0], $operand, $operator === 'NOT IN');
        }

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
}
