<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;

/** Merge refinements by full path before lowering each returned relationship to one native edge. */
final class EagerLoadCompiler
{
    /** @param array<string, QueryExpression|null> $nested */
    public static function expand(QueryExpression $query, array $nested = [], int $depth = 0, bool $defaults = true): QueryExpression
    {
        if ($depth > 8) {
            throw new LogicException('Default eager loading exceeds eight levels at '.$query->queryModel()->name.'.');
        }

        $query = $query->distribute();
        if ($query->kind === 'conditional') {
            $query = clone $query;
            $query->children = [$query->children[0], self::expand($query->children[1], $nested, $depth, $defaults), self::expand($query->children[2], $nested, $depth, $defaults)];

            return $query;
        }

        $model = $query->queryModel();
        $paths = [...($defaults ? $model->eagerLoads() : []), ...$nested];
        $query = self::extract($query, $paths);
        $removed = ScopeCompiler::removals($query);
        foreach ($removed === null ? [] : ScopeCompiler::globals($model) as $name => $scope) {
            if (! in_array((string) $name, $removed ?? [], true)) {
                self::extract($scope, $paths);
            }
        }

        $groups = [];
        foreach ($paths as $path => $callback) {
            [$head, $tail] = array_pad(explode('.', $path, 2), 2, '');
            if ($model->returnsRelation($head)) {
                $groups[$head][$tail] = $callback;
            }
        }

        if ($groups === []) {
            return $query;
        }

        // Keep the result selector (especially pagination) as the outermost operation.
        $terminal = $query->kind === 'paginate' || $query->kind === 'method' && $query->isTerminal();
        $base = $terminal ? $query->children[0] : $query;
        foreach ($groups as $head => $tails) {
            $callback = $tails[''] ?? null;
            unset($tails['']);
            $symbol = $callback?->stringValue() ?? 'zeroRelated';
            $target = $model->registry->get($model->registry->relation($model, $head)['model']);
            $body = $callback?->children[0] ?? new QueryExpression('local', $symbol, model: $target, type: 'query');
            $body = self::expand($body, $tails, $depth + 1, $defaults);
            $args = [$base, ScopeCompiler::value($head)];
            if ($callback !== null || $body->kind !== 'local') {
                $args[] = new QueryExpression('callback', $symbol, [$body], type: 'callback');
            }

            $base = new QueryExpression('method', 'with', $args, $model, 'query', $base->singular);
        }

        if ($terminal) {
            $query->children[0] = $base;

            return $query;
        }

        return $base;
    }

    /** Extract only same-row includes. Predicates and their callbacks stay intact.
     * @param  array<string, QueryExpression|null>  $paths
     */
    private static function extract(QueryExpression $query, array &$paths): QueryExpression
    {
        if (! in_array($query->kind, ['method', 'guarded', 'scope', 'paginate'], true)) {
            return $query;
        }

        $node = clone $query;
        $node->children[0] = self::extract($node->children[0], $paths);
        if ($node->kind === 'scope') {
            $node->children = [$node->children[0], self::extract($node->children[1], $paths), ...array_slice($node->children, 2)];
        } elseif ($node->kind === 'method' && $node->value === 'with') {
            $paths[$node->children[1]->stringValue()] = $node->children[2] ?? null;

            return $node->children[0];
        }

        return $node;
    }
}
