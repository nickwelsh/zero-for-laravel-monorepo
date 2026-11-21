<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Query\ExpressionBuilder;

/** Lower author conveniences to ordinary checked groups, refinements and orderings. */
trait CompilesEloquentPredicates
{
    /** @param array<string, QueryExpression> $variables */
    private function eloquentCall(Expr\MethodCall $node, string $method, QueryExpression $receiver, array $variables): ?QueryExpression
    {
        if ($method === 'whereExists') {
            $this->unsupported($node, 'Use has() or whereHas(); native exists options are not query declarations.');
        }
        $range = match ($method) {
            'where', 'orWhere' => [1, 3],
            'orderBy' => [1, 2], 'latest', 'oldest' => [0, 1],
            'orderByDesc', 'take', 'whereKey', 'whereNull', 'orWhereNull', 'whereNotNull', 'orWhereNotNull', 'orDoesntHave' => [1, 1],
            'whereIn', 'whereNotIn', 'orWhereIn', 'orWhereNotIn', 'whereBetween', 'orWhereBetween', 'whereNotBetween', 'orWhereNotBetween' => [2, 2],
            'has' => [1, 5], 'orHas', 'doesntHave' => [1, 3], 'whereDoesntHave', 'orWhereDoesntHave' => [1, 2],
            'whereHas', 'orWhereHas', 'withWhereHas' => [1, 4], 'whereRelation', 'orWhereRelation' => [3, 4],
            'when', 'unless' => [2, 3], default => null,
        };
        if ($range === null) {
            return null;
        }
        $args = [];
        foreach ($node->getArgs() as $arg) {
            if ($arg->name !== null || $arg->unpack || $arg->byRef) {
                $this->unsupported($arg, 'Predicate arguments must be positional.');
            }
            $args[] = $arg->value;
        }
        if (count($args) < $range[0] || count($args) > $range[1]) {
            $this->unsupported($node, "Invalid argument count for {$method}.");
        }
        $rootName = "\0root".++$this->callbackId;
        $root = new Expr\Variable($rootName);
        $child = new Expr\Variable("\0child".$this->callbackId);
        $variables[$rootName] = $receiver;
        $str = static fn (string $s): Scalar\String_ => new Scalar\String_($s);
        $call = static fn (Expr $q, string $m, Expr ...$operands): Expr\MethodCall => new Expr\MethodCall($q, $m, array_map(static fn (Expr $e): Node\Arg => new Node\Arg($e), array_values($operands)), $node->getAttributes());
        $callback = static fn (Expr $body): Expr\ArrowFunction => new Expr\ArrowFunction(['params' => [new Node\Param($child)], 'expr' => $body], $node->getAttributes());
        $value = fn (Expr $e): QueryExpression => $this->expression($e, $variables);
        $symbol = function (QueryExpression $value) use (&$variables): Expr\Variable {
            $name = "\0operand".++$this->callbackId;
            $variables[$name] = $value;

            return new Expr\Variable($name);
        };
        $or = str_starts_with($method, 'or');
        $where = $or ? 'orWhere' : 'where';
        $not = str_contains($method, 'Not');
        $result = null;
        if (in_array($method, ['where', 'orWhere'], true)) {
            if (count($args) !== 1 || $args[0] instanceof Expr\Closure || $args[0] instanceof Expr\ArrowFunction) {
                return null;
            }
            $body = $child;
            // Laravel permits keyed equalities and comparison tuples in the same array.
            if ($args[0] instanceof Expr\Array_) {
                $keys = [];
                foreach ($args[0]->items as $item) {
                    if ($item === null || $item->unpack || $item->byRef) {
                        $this->unsupported($node, 'Predicates require field/value pairs or comparison tuples.');
                    }
                    if ($item->key instanceof Scalar\String_) {
                        if (isset($keys[$item->key->value])) {
                            $this->unsupported($item, 'Predicate keys must be unique.');
                        }
                        $keys[$item->key->value] = true;
                        $body = $call($body, $where, $item->key, $item->value);
                    } elseif ($item->key === null && $item->value instanceof Expr\Array_) {
                        $tuple = $value($item->value);
                        if ($tuple->kind !== 'list' || ! in_array(count($tuple->children), [2, 3], true)) {
                            $this->unsupported($item, 'A comparison tuple requires two or three entries.');
                        }
                        $body = $call($body, $where, ...array_map($symbol, $tuple->children));
                    } else {
                        $this->unsupported($item, 'Predicates require field/value pairs or comparison tuples.');
                    }
                }
            } else {
                $array = $value($args[0]);
                if (! in_array($array->kind, ['object', 'list'], true)) {
                    return null;
                }
                foreach ($array->children as $item) {
                    if ($item->kind === 'property') {
                        $body = $call($body, $where, $str($item->stringValue()), $symbol($item->children[0]));
                    } elseif ($item->kind === 'list' && in_array(count($item->children), [2, 3], true)) {
                        $body = $call($body, $where, ...array_map($symbol, $item->children));
                    } else {
                        $this->unsupported($node, 'Predicates require field/value pairs or comparison tuples.');
                    }
                }
            }
            $result = $call($root, $where, $callback($body));
        } elseif (in_array($method, ['orderBy', 'orderByDesc', 'latest', 'oldest'], true)) {
            $direction = $method === 'orderByDesc' || $method === 'latest' ? 'desc' : 'asc';
            if (isset($args[1])) {
                $dir = $value($args[1]);
                if ($dir->kind !== 'literal' || ! is_string($dir->value) || strtolower($dir->value) === $dir->value) {
                    return null;
                }
                $direction = strtolower($dir->value);
            }
            $field = ! isset($args[0]) || in_array($method, ['latest', 'oldest'], true) && $value($args[0])->type === 'null'
                ? $str($receiver->queryModel()->createdAt ?? 'created_at') : $args[0];
            $result = $call($root, 'orderBy', $field, $str($direction));
        } elseif ($method === 'take') {
            $result = $call($root, 'limit', ...$args);
        } elseif ($method === 'whereKey') {
            $result = $call($root, $value($args[0])->type === 'list' ? 'whereIn' : 'where', $str($receiver->queryModel()->key), $args[0]);
        } elseif (str_ends_with($method, 'Null')) {
            $fields = $value($args[0]);
            $result = $root;
            foreach ($fields->kind === 'list' ? $fields->children : [$fields] as $field) {
                $result = $call($result, $where, $symbol($field), $str($not ? '!=' : '='), new Expr\ConstFetch(new Name('null')));
            }
        } elseif (str_ends_with($method, 'Between')) {
            $bounds = $value($args[1]);
            if ($bounds->kind !== 'list' || count($bounds->children) !== 2) {
                $this->unsupported($node, 'Between requires two ordered bounds.');
            }
            [$lower, $upper] = $bounds->children;
            if ($lower->type === 'null' || $upper->type === 'null') {
                $result = ! $not || $lower->type === $upper->type
                    ? $call($root, $where.'In', $args[0], new Expr\Array_)
                    : $call($root, $where, $args[0], $str($lower->type === 'null' ? '>' : '<'), $symbol($lower->type === 'null' ? $upper : $lower));
            } else {
                $body = $call($child, 'where', $args[0], $str($not ? '<' : '>='), $symbol($lower));
                $body = $call($body, $not ? 'orWhere' : 'where', $args[0], $str($not ? '>' : '<='), $symbol($upper));
                $result = $call($root, $where, $callback($body));
            }
        } elseif (str_ends_with($method, 'In')) {
            $field = $value($args[0]);
            $list = $value($args[1]);
            if ($field->kind !== 'literal' || ! is_string($field->value) || ! isset($receiver->queryModel()->columns[$field->value]) || $list->kind !== 'list') {
                $this->unsupported($node, 'Membership requires an exposed column and a list.');
            }
            $members = [];
            foreach ($list->children as $member) {
                $member = $this->predicateOperand($node, $receiver, $field, $member);
                if (! in_array($member->type, ['int', 'string', 'bool', 'float', 'null'], true) || $member->kind === 'context' && ContextDefinition::configured()->fields[$member->stringValue()]['nullable']) {
                    $this->unsupported($node, 'Membership requires a scalar list.');
                }
                $members[] = $member;
            }
            $list = new QueryExpression('list', children: $members, type: 'list');

            return $this->mutate($receiver, new QueryExpression('method', $method, [$receiver->snapshot(), $field, $list], $receiver->model, 'query', $receiver->singular));
        } elseif ($method === 'when' || $method === 'unless') {
            $condition = $value($args[0]);
            if (! $this->boolean($condition) || $this->symbolicCondition($condition)) {
                $this->unsupported($node, 'Conditional composition requires an explicit boolean condition known while constructing the query.');
            }
            $branches = [$receiver->snapshot(), $receiver->snapshot()];
            foreach (array_slice($args, 1) as $index => $fn) {
                $positive = ($method === 'when') === ($index === 0);
                $locals = $this->branch($positive ? $this->narrow($condition, $variables) : $variables);
                if (! $fn instanceof Expr\Closure && ! $fn instanceof Expr\ArrowFunction) {
                    $this->unsupported($fn, 'Conditional composition requires literal query callbacks.');
                }
                // Captured builders could mutate an alias outside the chosen branch.
                foreach ($locals as $name => $local) {
                    if ($name !== $rootName && $local->type === 'query') {
                        unset($locals[$name]);
                    }
                }
                $fn = clone $fn;
                if (count($fn->params) === 2) {
                    $param = array_pop($fn->params);
                    if (! $param->var instanceof Expr\Variable || ! is_string($param->var->name) || $param->byRef || $param->variadic || $param->default !== null || $param->type !== null && (! $param->type instanceof Node\Identifier || $param->type->name !== 'bool')) {
                        $this->unsupported($param, 'The optional condition callback parameter must be a boolean.');
                    }
                    $locals[$param->var->name] = ScopeCompiler::value($positive);
                }
                // Reuse ordinary callback verification, including provenance and type annotations.
                $group = $this->expression($call($root, 'where', $fn), $locals)->snapshot();
                $refine = $group->children[1];
                $branches[$positive ? 0 : 1] = $this->substitutePredicateRoot($refine->children[0], $refine->stringValue(), $receiver->snapshot());
            }
            if ($branches[0]->singular !== $branches[1]->singular || $branches[0]->isTerminal() || $branches[1]->isTerminal()) {
                $this->unsupported($node, 'Conditional callbacks may refine the query but cannot select results.');
            }
            if ($condition->kind === 'literal') {
                return $this->mutate($receiver, $branches[$condition->value ? 0 : 1]);
            }
            if ($this->scopeStack !== []) {
                $this->unsupported($node, 'Conditional composition inside an Eloquent scope requires a literal boolean.');
            }

            return $this->mutate($receiver, new QueryExpression('conditional', children: [$condition, ...$branches], model: $receiver->model, type: 'query', singular: $receiver->singular));
        } elseif (str_ends_with($method, 'Relation')) {
            $result = $call($root, $or ? 'orWhereHas' : 'whereHas', $args[0], $callback($call($child, 'where', ...array_slice($args, 1))));
        } else {
            $relation = $value($args[0]);
            if ($relation->kind !== 'literal' || ! is_string($relation->value)) {
                $this->unsupported($node, 'Relationship filters require a literal path.');
            }
            $absence = str_contains(strtolower($method), 'doesnthave');
            $has = in_array($method, ['has', 'orHas'], true);
            $opIndex = $has ? 1 : 2;
            $op = ! $absence && isset($args[$opIndex]) ? $value($args[$opIndex]) : ScopeCompiler::value($absence ? '<' : '>=');
            $count = ! $absence && isset($args[$opIndex + 1]) ? $value($args[$opIndex + 1]) : ScopeCompiler::value(1);
            if ($op->kind !== 'literal' || ! is_string($op->value) || $count->kind !== 'literal' || ! is_int($count->value)) {
                $this->unsupported($node, 'Relationship counts must be literal existence or absence comparisons.');
            }
            try {
                $absent = ExpressionBuilder::absence($op->value, $count->value);
            } catch (InvalidArgumentException $e) {
                $this->unsupported($node, $e->getMessage());
            }
            $boolean = $method === 'has' ? ($args[3] ?? null) : ($method === 'doesntHave' ? ($args[1] ?? null) : null);
            if ($boolean !== null) {
                $junction = $value($boolean);
                if ($junction->kind !== 'literal' || ! in_array($junction->value, ['and', 'or'], true)) {
                    $this->unsupported($node, 'Relationship predicates require and/or.');
                }
                $or = $junction->value === 'or';
            }
            $refinement = $args[$has ? 4 : ($method === 'doesntHave' ? 2 : 1)] ?? null;
            if ($refinement instanceof Expr\ConstFetch && strtolower($refinement->name->toString()) === 'null') {
                $refinement = null;
            }
            $refine = $refinement !== null ? [$refinement] : [];
            $include = $refine;
            $path = $relation->value;
            if (str_contains($path, '.')) {
                [$path, $tail] = explode('.', $path, 2);
                $outerAbsent = $op->value === '<' && $count->value === 1;
                $nested = $absent && ! $outerAbsent && str_contains($tail, '.')
                    ? $call($child, 'whereHas', $str($tail), $refinement ?? new Expr\ConstFetch(new Name('null')), $str($op->value), new Scalar\Int_($count->value))
                    : $call($child, $absent && ! $outerAbsent ? 'whereDoesntHave' : 'whereHas', $str($tail), ...$refine);
                $refine = [$callback($nested)];
                $absent = $outerAbsent;
            } elseif (in_array($method, ['whereHas', 'whereDoesntHave'], true) && count($args) === 1 + count($refine)) {
                return null;
            }
            $base = $or ? $child : $root;
            $result = $call($base, $absent ? 'whereDoesntHave' : 'whereHas', $str($path), ...$refine);
            if ($or) {
                $result = $call($root, 'orWhere', $callback($result));
            }
            if ($method === 'withWhereHas') {
                $result = $call($result, 'with', $str($relation->value), ...$include);
            }
        }

        return $this->expression($result, $variables);
    }

    private function predicateOperand(Node $node, QueryExpression $receiver, QueryExpression $field, QueryExpression $operand): QueryExpression
    {
        $column = is_string($field->value) ? $receiver->queryModel()->columns[$field->value] ?? null : null;
        if (in_array($operand->type, ['date', 'dateOnly'], true)) {
            if ($column === null || ! $column['date']) {
                $this->unsupported($node, 'Date arguments require a date-cast column.');
            }

            return new QueryExpression('dateOperand', $column['dateFormat'] ?? null, [$operand], type: $column['type'] === 'string' ? 'string' : 'int');
        }
        if (($column['date'] ?? false) && $operand->kind === 'literal' && is_string($operand->value)) {
            $parts = date_parse($operand->value);
            if ($parts['year'] === false || $parts['month'] === false || $parts['day'] === false || isset($parts['relative']) || $parts['error_count'] || $parts['warning_count']) {
                $this->unsupported($node, 'Date literals must be valid absolute dates.');
            }
            $date = CarbonImmutable::parse($operand->value, 'UTC')->utc();

            return ScopeCompiler::value(isset($column['dateFormat']) ? $date->format($column['dateFormat']) : $date->getTimestampMs());
        }
        if (($column['date'] ?? false) && $operand->type === 'string') {
            $this->unsupported($node, 'Date string arguments require a validated date or date_format contract.');
        }

        return $operand;
    }

    /** Trusted context and option placeholders are operands, not PHP branch values. */
    private function symbolicCondition(QueryExpression $expression): bool
    {
        if (in_array($expression->kind, ['context', 'optionInput', 'optionContains'], true)) {
            return true;
        }
        foreach ($expression->children as $child) {
            if ($this->symbolicCondition($child)) {
                return true;
            }
        }

        return false;
    }

    private function substitutePredicateRoot(QueryExpression $tree, string $name, QueryExpression $root): QueryExpression
    {
        if ($tree->kind === 'local' && $tree->value === $name) {
            return $root;
        }
        $copy = clone $tree;
        $copy->children = array_map(fn (QueryExpression $child): QueryExpression => $this->substitutePredicateRoot($child, $name, $root), $tree->children);

        return $copy;
    }
}
