import type { Column } from '@nickwelsh/zero-for-laravel';
import {
    validateFilter,
    type Capabilities,
    type FilterNode,
    type FilterScope,
    type RelationCapability,
    type Sort,
} from './index';
import type { ColumnDefinition } from './columns';

const operatorLabels = {
    equals: 'Equals',
    notEquals: 'Does not equal',
    contains: 'Contains',
    startsWith: 'Starts with',
    endsWith: 'Ends with',
    in: 'Is one of',
    gt: 'Greater than',
    gte: 'At least',
    lt: 'Less than',
    lte: 'At most',
    isNull: 'Is empty',
    isNotNull: 'Is not empty',
    some: 'Matching record',
    has: 'Has',
    any: 'Has any of',
    all: 'Has all of',
} as const;
export type TableOperator = keyof typeof operatorLabels;
export type FilterOperatorOption = {
    readonly label: string;
    readonly arity: 'zero' | 'one' | 'many';
    readonly valueType: Column['type'] | 'date' | 'none' | 'filter';
    readonly choices: readonly { value: string | number | boolean; label: string }[];
} & (
    | { readonly id: 'has' | 'any' | 'all'; readonly source: 'query' }
    | { readonly id: 'some'; readonly source: 'nested' }
    | { readonly id: Exclude<TableOperator, 'some'>; readonly source: 'entry' | 'finite' | 'none' }
);
export interface FilterFieldOption {
    readonly field: string;
    readonly label: string;
    /** Absolute path in the declared filter tree, also accepted by the option picker. */
    readonly path: readonly string[];
    readonly kind: 'scalar' | 'relation';
    readonly operators: readonly FilterOperatorOption[];
}
function headline(field: string) {
    const words = field.replaceAll('_', ' ').replace(/([a-z])([A-Z])/g, '$1 $2');
    return words.charAt(0).toUpperCase() + words.slice(1);
}
function label(capabilities: Capabilities, field: string) {
    return capabilities.displayColumns?.find((column) => column.field === field)?.label ?? headline(field);
}
function relationAt(scope: FilterScope, field: string): RelationCapability {
    if (!scope.relations || !Object.hasOwn(scope.relations, field)) throw new Error('Unknown table relation: ' + field);
    return scope.relations[field]!;
}
function scopeAt(scope: FilterScope, path: readonly string[]) {
    for (const field of path) {
        const relation = relationAt(scope, field);
        if (!relation.operators.includes('some')) throw new Error('Nested rules are not allowed for ' + field);
        scope = relation;
    }
    return scope;
}
function describeOperator(id: string, column: Column, query: boolean, date = column.date): FilterOperatorOption {
    if (!Object.hasOwn(operatorLabels, id)) throw new Error('Unknown table operator: ' + id);
    if (id === 'some')
        return { id, label: operatorLabels.some, source: 'nested', arity: 'one', valueType: 'filter', choices: [] };
    const zero = id === 'isNull' || id === 'isNotNull';
    const choices = column.enum ?? (column.type === 'boolean' ? [true, false] : []);
    const input =
        query && (id === 'has' || id === 'any' || id === 'all')
            ? ({ id, source: 'query' } as const)
            : ({
                  id: id as Exclude<TableOperator, 'some'>,
                  source: zero ? 'none' : choices.length ? 'finite' : 'entry',
              } as const);
    return {
        ...input,
        label: operatorLabels[id as TableOperator],
        valueType: zero ? 'none' : date ? 'date' : column.type,
        arity: zero ? 'zero' : ['in', 'any', 'all'].includes(id) ? 'many' : 'one',
        choices: zero || query ? [] : choices.map((value) => ({ value, label: String(value) })),
    };
}
export function filterFields(capabilities: Capabilities, path: readonly string[] = []): readonly FilterFieldOption[] {
    const scope = scopeAt(capabilities, path);
    return [
        ...Object.entries(scope.filters).map(([field, filter]) => ({
            field,
            path: [...path, field],
            kind: 'scalar' as const,
            label: filter.label ?? (path.length ? headline(field) : label(capabilities, field)),
            operators: filter.operators.map((id) => describeOperator(id, filter.column, false, filter.kind === 'date')),
        })),
        ...Object.entries(scope.relations ?? {}).flatMap(([field, relation]) => {
            const operators = relation.operators.map((id) =>
                describeOperator(id, relation.keyColumn, !!relation.choices),
            );
            return operators.length
                ? [{ field, path: [...path, field], kind: 'relation' as const, label: relation.label, operators }]
                : [];
        }),
    ];
}

/** Build one rule in its local scope. Pass nested rules (or a group of them) to their parent's `some`. */
export function buildFilter(
    capabilities: Capabilities,
    path: readonly string[],
    operator: TableOperator,
    operand?: unknown,
): FilterNode {
    const field = path.at(-1);
    if (!field) throw new Error('Missing table filter path.');
    const scope = scopeAt(capabilities, path.slice(0, -1));
    const kind = scope.relations && Object.hasOwn(scope.relations, field) ? 'relation' : 'condition';
    const node = {
        kind,
        field,
        operator,
        ...(operator === 'some' ? { filter: operand } : operand === undefined ? {} : { value: operand }),
    } as FilterNode;
    validateFilter(scope, node);
    return node;
}
export function filterGroup(kind: 'and' | 'or', children: readonly FilterNode[]): FilterNode {
    if (!children.length) throw new Error('A filter group needs at least one rule.');
    return { kind, children };
}

/** Index into group children; zero is the sole child of a same-record (`some`) rule. */
export function editFilter(
    node: FilterNode | null,
    at: readonly number[],
    change: (old: FilterNode | null) => FilterNode | null,
): FilterNode | null {
    if (!at.length) return change(node);
    const [index, ...rest] = at;
    if (index !== undefined && Number.isInteger(index) && index >= 0) {
        if ((node?.kind === 'and' || node?.kind === 'or') && index < node.children.length) {
            const child = editFilter(node.children[index]!, rest, change);
            const children = [...node.children];
            children.splice(index, 1, ...(child ? [child] : []));
            return children.length ? { ...node, children } : null;
        }
        if (node?.kind === 'relation' && node.operator === 'some' && index === 0) {
            const filter = editFilter(node.filter, rest, change);
            return filter ? { ...node, filter } : null;
        }
    }
    throw new Error('Unknown table filter position.');
}

export function orderingChoices<F extends string>(
    capabilities: Capabilities,
    fields: readonly F[],
    current: readonly (Sort & { readonly field: F })[],
    defaults: readonly Sort[],
    limit: number,
) {
    return {
        current,
        // Generated defaults are validated against this axis when the session is constructed.
        defaults: defaults as readonly (Sort & { readonly field: F })[],
        limit,
        directions: ['asc', 'desc'] as const,
        choices: fields.map((field) => ({
            field,
            label: label(capabilities, field),
            direction: current.find((s) => s.field === field)?.direction,
        })),
    };
}

export interface DisplayColumn<Row> extends ColumnDefinition<Row> {
    readonly preview?: (row: Row) => { value: unknown; truncated: boolean };
}
export function displayColumns<Row>(capabilities: Capabilities): readonly DisplayColumn<Row>[] {
    const defaults =
        capabilities.displayColumns ??
        Object.entries(capabilities.columns)
            .filter(([, c]) => c.type !== 'json')
            .map(([field]) => ({ field, label: headline(field) }));
    return defaults.map(({ field, label: header }) => {
        if (Object.hasOwn(capabilities.columns, field))
            return { id: field, header, field: field as keyof Row & string };
        const relation = relationAt(capabilities, field);
        const lookup = relation.lookup;
        if (!lookup) throw new Error('Unknown table lookup: ' + field);
        const preview = (row: Row) => {
            const related = (row as Record<string, unknown>)[field];
            if (relation.cardinality === 'one')
                return {
                    value: (related as Record<string, unknown> | undefined)?.[lookup.field] ?? null,
                    truncated: false,
                };
            if (related == null) return { value: [], truncated: false };
            if (!Array.isArray(related)) throw new Error('Invalid table lookup collection.');
            return {
                value: related.slice(0, lookup.limit).map((child: Record<string, unknown>) => child[lookup.field]),
                truncated: related.length > lookup.limit,
            };
        };
        return { id: field, header, preview, value: (row: Row) => preview(row).value };
    });
}
