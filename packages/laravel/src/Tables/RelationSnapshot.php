<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use LogicException;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Permissions\Binding;
use ZeroForLaravel\Permissions\EloquentVisibility;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** SQL counterpart to ZeroQuery's correlated AST. A junction is itself a hop,
 * not a join on the root: multiple matching children must never inflate counts.
 */
final class RelationSnapshot
{
    private int $sequence = 0;

    public function __construct(private readonly ModelRegistry $registry, private readonly ?Authenticatable $actor, private readonly ResolvedContext $context) {}

    /** @param array<string, mixed> $condition A compiler-owned, context-resolved existence condition. */
    public function apply(Builder $query, array $condition, string $parent): void
    {
        $related = Input::object($condition['related'] ?? null, 'relationship');
        $ast = Input::object($related['subquery'] ?? null, 'subquery');
        $table = $ast['table'] ?? null;
        $op = $condition['op'] ?? null;
        if (! is_string($table) || ! in_array($op, ['EXISTS', 'NOT EXISTS'], true) || array_diff(array_keys($ast), ['table', 'alias', 'where', '__visibility']) !== []) {
            throw new LogicException('Table relation membership requires unbounded, membership-only scopes.');
        }

        $sub = $query->newQuery()->selectRaw('1');
        do {
            $alias = 'zero_table_relation_'.++$this->sequence;
        } while ($alias === $parent);

        $model = null;
        foreach ($this->registry->all() as $candidate) {
            if ($candidate->table === $table) {
                $model = $candidate;
                break;
            }
        }

        if ($model?->policyVisibility) {
            if (! isset($ast['__visibility'])) {
                throw new LogicException('A protected relation without visibleTo has no portable SQL lowering.');
            }

            if (! $this->actor instanceof Authenticatable || $ast['__visibility'] !== $this->context->userId() || $ast['__visibility'] !== Binding::id($this->actor->getAuthIdentifier())) {
                throw new AuthorizationException('Table relation counts require the authenticated visibility actor.');
            }

            $source = (new $model->class)->newQueryWithoutScopes();
            $sub->fromSub((new EloquentVisibility)->apply($source, $this->actor)->getQuery(), $alias);
        } elseif ($model !== null || isset($this->registry->pivots()[$table])) {
            $sub->from($table, $alias);
        } else {
            throw new LogicException('Unregistered table in relation condition.');
        }

        $correlation = Input::object($related['correlation'] ?? null, 'correlation');
        $parents = $correlation['parentField'] ?? null;
        $children = $correlation['childField'] ?? null;
        if (! is_array($parents) || ! is_array($children) || $parents === [] || count($parents) !== count($children)) {
            throw new LogicException('Invalid table relationship correlation.');
        }

        foreach ($parents as $index => $field) {
            $child = $children[$index] ?? null;
            if (! is_string($field) || ! is_string($child)) {
                throw new LogicException('Invalid table relationship columns.');
            }

            $sub->whereColumn($parent.'.'.$field, $alias.'.'.$child);
        }

        if (isset($ast['where'])) {
            $where = Input::object($ast['where'], 'condition');
            if ($model !== null) {
                Predicate::sql($sub, $where, $model, $this, $alias);
            } elseif (($where['type'] ?? null) === 'correlatedSubquery') {
                $this->apply($sub, $where, $alias);
            } else {
                throw new LogicException('Unsupported junction condition.');
            }
        }

        $query->addWhereExistsQuery($sub, not: $op === 'NOT EXISTS');
    }
}
