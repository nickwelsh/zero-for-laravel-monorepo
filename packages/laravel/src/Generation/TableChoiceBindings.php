<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use ZeroForLaravel\Tables\TableRelationPath;

/** Standalone queries supply eligibility. The picker always owns search, ordering and bounds. */
final class TableChoiceBindings
{
    /** @param list<ControllerQuery> $queries
     * @return array<string, ControllerQuery> keyed by the exact deployed operation contract
     */
    public static function for(TableRelationPath $path, string $operation, array $queries): array
    {
        $choices = $path->choices ?? throw new LogicException('Missing picker declaration.');
        $names = array_filter([$choices['list'] ?? null, $operation === 'resolve' ? ($choices['resolve'] ?? null) : null], static fn (?string $name): bool => $name !== null);
        $bindings = [];
        foreach (array_unique($names) as $name) {
            foreach ($queries as $query) {
                if ($query->standalone && ($query->action === $name || $query->name() === $name)) {
                    if ($query->parameters !== [] || $query->guards !== [] || $query->expression->model?->name !== $path->target->name || $query->expression->singular || ! self::membership(EagerLoadCompiler::expand($query->expression, defaults: false))) {
                        throw new LogicException($name.': table choices require a standalone, input-free eligibility query on '.$path->target->name.' without relation absence; the picker applies ordering and bounds.');
                    }

                    $bindings[$query->name()] = $query;

                    continue 2;
                }
            }

            throw new LogicException('Unknown standalone table choice query: '.$name.'.');
        }

        return $bindings;
    }

    private static function membership(QueryExpression $node): bool
    {
        if (in_array($node->kind, ['guarded', 'paginate', 'conditional', 'bound'], true) || $node->kind === 'method' && ! in_array($node->value, ['get', 'visibleTo', 'where', 'orWhere', 'whereIn', 'whereNotIn', 'orWhereIn', 'orWhereNotIn', 'whereHas'], true)) {
            return false;
        }

        foreach ($node->children as $child) {
            if (! self::membership($child)) {
                return false;
            }
        }

        return true;
    }
}
