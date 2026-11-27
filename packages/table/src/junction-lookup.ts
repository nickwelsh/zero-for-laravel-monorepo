import type { FilterScope, TableRelationScopes } from './index';

export interface JunctionLookup {
    readonly pivots: string;
    readonly target: string;
    readonly order: string;
}

type Refine = TableRelationScopes[string];
type Query = Parameters<Refine>[0];

/** Native Zero cannot limit a two-hop `related`. Limit the visible one-hop pivots instead. */
export function projectJunction(query: Query, edge: JunctionLookup, limit: number, visible: Refine): Query {
    return query.related(edge.pivots, (pivots) =>
        pivots
            .where((eb) => eb.exists(edge.target, visible))
            .orderBy(edge.order, 'asc')
            .limit(limit + 1)
            .related(edge.target, visible),
    );
}

/** Remove only the generated pivot envelope; the ordinary decoder still validates and decodes each target. */
export function junctionNormalizer(scope: FilterScope): (raw: unknown) => unknown {
    const edges = Object.entries(scope.relations ?? {}).flatMap(([field, relation]) =>
        relation.junction ? [{ field, ...relation.junction }] : [],
    );
    if (!edges.length) return (raw) => raw;
    const cache = new WeakMap<object, unknown>();
    function normalize(raw: unknown): unknown {
        if (typeof raw !== 'object' || raw === null) return raw;
        if (cache.has(raw)) return cache.get(raw);
        if (Array.isArray(raw)) {
            const rows = raw.map(normalize);
            cache.set(raw, rows);
            return rows;
        }
        const row = { ...raw } as Record<string, unknown>;
        for (const { field, pivots, target } of edges) {
            if (!Object.hasOwn(row, pivots)) continue;
            const links = row[pivots];
            if (!Array.isArray(links)) throw new Error('Expected an array of junction lookup rows.');
            row[field] = links.map((link: unknown) => {
                if (typeof link !== 'object' || link === null || !Object.hasOwn(link, target))
                    throw new Error('Missing target in junction lookup row.');
                return (link as Record<string, unknown>)[target];
            });
            delete row[pivots];
        }
        cache.set(raw, row);
        return row;
    }
    return normalize;
}
