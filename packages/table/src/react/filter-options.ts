import { useData } from '@nickwelsh/zero-for-laravel/react';
import type { Capabilities, RelationCapability, TableDescriptor } from '../index';

type ID = string | number;
type Choice = { value: ID; label: string };

/** A listing follows the open picker; resolution follows the selected IDs, even when closed. */
export function useTableFilterOptions<Row, C extends Capabilities>(
    { descriptor }: { descriptor: TableDescriptor<Row, C> },
    path: readonly string[],
    input: { operator: 'has' | 'any' | 'all'; selected: readonly ID[]; search: string; enabled: boolean },
) {
    let relations = descriptor.capabilities.relations;
    let relation: RelationCapability | undefined;
    for (const field of path) {
        relation = relations && Object.hasOwn(relations, field) ? relations[field] : undefined;
        relations = relation?.relations;
    }
    const source = relation?.choices;
    if (!source || !descriptor.options) throw new Error('This table field has no option source.');
    const ids = [...new Set(input.selected)];
    if (ids.length > 100 || (input.operator === 'has' && ids.length > 1))
        throw new Error('Too many selections for this table operator.');

    const listing = descriptor.options(path, { operation: 'list', search: input.search });
    const list = useData(listing, { enabled: input.enabled, ttl: 0 });
    const selection = useData(ids.length ? descriptor.options(path, { operation: 'resolve', ids }) : listing, {
        enabled: ids.length > 0,
        ttl: 0,
    });
    function choices(rows: readonly Readonly<Record<string, unknown>>[]): Choice[] {
        return rows.map((row) => {
            const value = row[source!.value];
            const label = row[source!.label];
            if ((typeof value !== 'string' && typeof value !== 'number') || typeof label !== 'string')
                throw new Error('Invalid table option value or label.');
            return { value, label };
        });
    }
    const items = list.status === 'success' ? choices(list.data) : [];
    const resolved = new Map(
        selection.status === 'success' ? choices(selection.data).map((item) => [item.value, item]) : [],
    );
    const unavailable = selection.status === 'success' ? ids.filter((id) => !resolved.has(id)) : [];
    const status = (value: typeof list.status) =>
        value === 'idle'
            ? 'idle'
            : value === 'success'
              ? 'ready'
              : value === 'failure' || value === 'stale'
                ? 'error'
                : 'loading';
    return {
        status: list.status === 'success' && !items.length ? ('empty' as const) : status(list.status),
        selectionStatus: unavailable.length ? ('unavailable' as const) : status(selection.status),
        items,
        selectedItems: ids.flatMap((id) => resolved.get(id) ?? []),
        unavailable,
        error: list.error ?? selection.error,
        // Replace the displayed page; do not retain rows or labels from old subscriptions.
        hasMore: list.status === 'success' && list.hasNextPage === true,
        loadMore: list.nextPage,
        hasPreviousPage: list.status === 'success' && list.hasPreviousPage,
        previousPage: list.previousPage,
    };
}
