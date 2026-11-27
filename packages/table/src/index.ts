/* oxlint-disable unicorn/no-array-sort -- ES2022 support; only newly allocated arrays are sorted. */
import { encodeDate, type Column, type ContractMap, type ModelRow } from '@nickwelsh/zero-for-laravel';
import { junctionNormalizer, projectJunction, type JunctionLookup } from './junction-lookup';
export { column, editors, parseEditorValue } from './columns';
export type { Editor, ColumnDefinition, CellChange, CellSubmitResult } from './columns';
export type { FilterFieldOption, FilterOperatorOption, TableOperator, DisplayColumn } from './discovery';

export type Scalar = string | number | boolean | null;
export type GroupPath = readonly { readonly field: string; readonly value: Scalar }[];
export interface FilterScope {
    readonly filters: Readonly<
        Record<
            string,
            {
                readonly field: string;
                readonly kind: string;
                readonly label?: string;
                readonly operators: readonly string[];
                readonly column: Column;
            }
        >
    >;
    readonly relations?: Readonly<Record<string, RelationCapability>>;
}
export type RelationOperator = 'some' | 'has' | 'any' | 'all';
export interface RelationCapability extends FilterScope {
    readonly model: string;
    readonly table: string;
    readonly junction?: JunctionLookup;
    readonly key: string;
    readonly keyColumn: Column;
    readonly cardinality: 'one' | 'many';
    readonly label: string;
    readonly operators: readonly RelationOperator[];
    readonly choices: {
        readonly value: string;
        readonly label: string;
        readonly list?: string;
        readonly resolve?: string;
    } | null;
    readonly lookup: { readonly field: string; readonly limit: number } | null;
}
export interface Capabilities extends FilterScope {
    readonly version: 2 | 3;
    readonly name: string;
    readonly contract: string;
    readonly stateContract?: string;
    readonly model: string;
    readonly key: readonly string[];
    readonly columns: Readonly<Record<string, Column>>;
    readonly displayColumns?: readonly { readonly field: string; readonly label: string }[];
    readonly sorts: readonly string[];
    readonly groups: Readonly<
        Record<
            string,
            {
                readonly field: string;
                readonly relation: string | null;
                readonly label: string | null;
                readonly model: string | null;
            }
        >
    >;
    readonly pageSize: number;
    readonly maxWindow?: number;
    readonly maxGroupDepth: number;
    readonly defaults: { readonly sort: readonly Sort[]; readonly groupBy: readonly Sort[] };
    readonly metadataURL: string;
}
type Value<C extends Column> = C['date'] extends true
    ? string
    : C['enum'] extends readonly (string | number)[]
      ? C['enum'][number]
      : { string: string; number: number; boolean: boolean; json: never }[C['type']];
type Condition<C extends FilterScope> = {
    [K in keyof C['filters'] & string]: {
        kind: 'condition';
        field: K;
    } & (
        | { operator: Extract<'isNull' | 'isNotNull', C['filters'][K]['operators'][number]>; value?: never }
        | {
              operator: Extract<'in', C['filters'][K]['operators'][number]>;
              value: readonly Value<C['filters'][K]['column']>[];
          }
        | {
              operator: Exclude<C['filters'][K]['operators'][number], 'in' | 'isNull' | 'isNotNull'>;
              value: Value<C['filters'][K]['column']>;
          }
    );
}[keyof C['filters'] & string];
type Relations<C extends FilterScope> = 'relations' extends keyof C ? NonNullable<C['relations']> : {};
type RelationRule<K, R extends RelationCapability> = {
    kind: 'relation';
    field: K;
    operator: R['operators'][number];
} & (
    | { operator: 'some'; filter: FilterNode<R> }
    | { operator: 'has'; value: Value<R['keyColumn']> }
    | { operator: 'any' | 'all'; value: readonly Value<R['keyColumn']>[] }
);
type Related<C extends FilterScope> = {
    [K in keyof Relations<C> & string]: Relations<C>[K] extends RelationCapability
        ? RelationRule<K, Relations<C>[K]>
        : never;
}[keyof Relations<C> & string];
export type FilterNode<C extends FilterScope = Capabilities> =
    | Condition<C>
    | Related<C>
    | { kind: 'and' | 'or'; children: readonly FilterNode<C>[] };
export interface Sort {
    readonly field: string;
    readonly direction: 'asc' | 'desc';
}
export interface TableState<C extends Capabilities = Capabilities> {
    readonly filter: FilterNode<C> | null;
    readonly sort: readonly { readonly field: C['sorts'][number]; readonly direction: 'asc' | 'desc' }[];
    readonly groupBy: readonly { readonly field: keyof C['groups'] & string; readonly direction: 'asc' | 'desc' }[];
    readonly expanded: readonly string[];
}
export type TableQueryInput<C extends Capabilities = Capabilities> = Omit<TableState<C>, 'expanded'> & {
    readonly path: GroupPath;
    readonly __window?: number;
    readonly __generation?: string;
    /** Identity-only draft visibility read, independent of the current view. */
    readonly __row?: readonly (string | number | boolean)[];
    /** Renew visibility after a failed optimistic write has settled. */
    readonly __refresh?: string;
};
export interface Selection<Row> {
    readonly key: string;
    readonly one: boolean;
    readonly definitions?: unknown;
    readonly queries?: unknown;
    request(): unknown;
    decode(raw: unknown): readonly Row[];
}
// applyTableQuery selects these target rows dynamically, so the native base query omits them from its type.
type LookupFields<D extends ContractMap, R extends Readonly<Record<string, RelationCapability>>> = {
    readonly [K in keyof R as R[K]['lookup'] extends NonNullable<RelationCapability['lookup']> ? K : never]: {
        many: readonly ModelRow<D, R[K]['model']>[];
        one: ModelRow<D, R[K]['model']> | undefined;
    }[R[K]['cardinality']];
};
type TableRow<C extends Capabilities, S extends Selection<unknown>> = ReturnType<S['decode']>[number] &
    (S['definitions'] extends ContractMap ? LookupFields<S['definitions'], NonNullable<C['relations']>> : unknown);
export interface TableDescriptor<Row, C extends Capabilities = Capabilities> {
    readonly capabilities: C;
    rows(input: TableQueryInput<C>): Selection<Row>;
    rowId(row: Row): string;
    options?(path: readonly string[], input: TableOptionInput): Selection<Readonly<Record<string, unknown>>>;
}
export type TableOptionInput =
    | { operation: 'list'; search: string }
    | { operation: 'resolve'; ids: readonly (string | number)[] };
export function createTable<C extends Capabilities, S extends Selection<unknown>>(
    capabilities: C,
    rows: (input: TableQueryInput<C>) => S,
    options?: TableDescriptor<unknown, C>['options'],
): TableDescriptor<TableRow<C, S>, C> {
    type Row = TableRow<C, S>;
    const normalize = junctionNormalizer(capabilities);
    return Object.freeze({
        capabilities,
        ...(options ? { options } : {}),
        rows: (input: TableQueryInput<C>) => {
            const selection = rows(input);
            return {
                ...selection,
                pageSize: undefined,
                decode: (raw: unknown) => selection.decode(normalize(raw)) as readonly Row[],
            };
        },
        rowId: (row: Row) =>
            'r:' + JSON.stringify(capabilities.key.map((field) => (row as Record<string, unknown>)[field])),
    });
}
export function groupId(path: GroupPath): string {
    return 'g:' + JSON.stringify(path);
}
export function queryKey(input: unknown): string {
    return JSON.stringify(input, (_key, value: unknown) =>
        value && typeof value === 'object' && !Array.isArray(value)
            ? Object.fromEntries(Object.entries(value).sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0)))
            : value,
    );
}
function invalid(message: string): never {
    throw new Error('Invalid table state: ' + message);
}
function keys(value: object, allowed: readonly string[]): void {
    if (Object.keys(value).some((key) => !allowed.includes(key))) invalid('unknown field');
}
function scalar(value: unknown, column: Column): void {
    if (
        value === null
            ? !column.nullable
            : typeof value !== column.type || (typeof value === 'number' && !Number.isFinite(value))
    )
        invalid('field value');
    if (value !== null && column.enum && !column.enum.includes(value as string | number)) invalid('enum value');
}
export function validateState<C extends Capabilities>(capabilities: C, state: TableState<C>): void {
    if (![2, 3].includes(capabilities.version)) invalid('unsupported protocol');
    if (!Array.isArray(state.sort) || !Array.isArray(state.groupBy) || !Array.isArray(state.expanded))
        invalid('state lists');
    if (
        state.expanded.length > 100 ||
        state.expanded.some((id) => typeof id !== 'string' || new TextEncoder().encode(id).length > 2048)
    )
        invalid('expanded groups');
    if (
        state.groupBy.length > capabilities.maxGroupDepth ||
        new Set(state.groupBy.map((rule) => rule?.field)).size !== state.groupBy.length ||
        state.groupBy.some(
            (rule) =>
                !rule ||
                typeof rule !== 'object' ||
                Array.isArray(rule) ||
                typeof rule.field !== 'string' ||
                !Object.hasOwn(capabilities.groups, rule.field) ||
                !['asc', 'desc'].includes(rule.direction),
        )
    )
        invalid('grouping');
    state.groupBy.forEach((rule) => keys(rule, ['field', 'direction']));
    if (
        new Set(state.sort.map((sort) => sort.field)).size !== state.sort.length ||
        state.sort.some((sort) => !capabilities.sorts.includes(sort.field) || !['asc', 'desc'].includes(sort.direction))
    )
        invalid('sort');
    state.sort.forEach((sort) => keys(sort, ['field', 'direction']));
    if (state.filter !== null) validateFilter(capabilities, state.filter);
}

export function validateFilter(root: FilterScope, rule: FilterNode): void {
    let terms = 0;
    function visit(scope: FilterScope, node: FilterNode, depth: number): void {
        if (++terms > 100 || depth > 8) invalid('filter complexity');
        if (!node || typeof node !== 'object' || Array.isArray(node)) invalid('filter node');
        if ((node.kind === 'relation' || node.kind === 'condition') && typeof node.field !== 'string')
            invalid('filter field');
        if (node.kind === 'relation') {
            const relation =
                scope.relations && Object.hasOwn(scope.relations, node.field) ? scope.relations[node.field] : undefined;
            if (!relation) invalid('relation capability');
            if (!relation.operators.includes(node.operator)) invalid('relation operator');
            if (node.operator === 'some') {
                keys(node, ['kind', 'field', 'operator', 'filter']);
                visit(relation, node.filter, depth + 1);
                return;
            }
            keys(node, ['kind', 'field', 'operator', 'value']);
            const ids = node.operator === 'has' ? [node.value] : node.value;
            if (!Array.isArray(ids) || !ids.length || ids.length > 100) invalid('membership');
            if (node.operator === 'all' && (terms += new Set(ids).size - 1) > 100) invalid('filter complexity');
            for (const id of ids) {
                if (typeof id !== 'string' && typeof id !== 'number') invalid('relation ID');
                if (typeof id === 'string' && (!id.length || Array.from(id).length > 1000)) invalid('relation ID');
                scalar(id, relation.keyColumn);
            }
            return;
        }
        keys(node, node.kind === 'condition' ? ['kind', 'field', 'operator', 'value'] : ['kind', 'children']);
        if (node.kind !== 'condition') {
            if (!['and', 'or'].includes(node.kind) || !Array.isArray(node.children) || !node.children.length)
                invalid('filter group');
            node.children.forEach((child) => visit(scope, child, depth + 1));
            return;
        }
        const filter = Object.hasOwn(scope.filters, node.field) ? scope.filters[node.field] : undefined;
        if (!filter || !filter.operators.includes(node.operator)) invalid('filter capability');
        if (node.operator === 'isNull' || node.operator === 'isNotNull') {
            if ('value' in node) invalid('null operation');
            return;
        }
        const values = node.operator === 'in' ? node.value : [node.value];
        if (!Array.isArray(values) || !values.length || values.length > 100) invalid('membership');
        for (const value of values) {
            if (value === null) invalid('explicit null operation required');
            if (filter.kind === 'date') {
                if (
                    typeof value !== 'string' ||
                    !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,3})?(?:Z|[+-]\d{2}:\d{2})$/.test(value) ||
                    !Number.isFinite(Date.parse(value))
                )
                    invalid('date offset');
                const [year, month, day] = value.slice(0, 10).split('-').map(Number);
                const calendar = new Date(0);
                calendar.setUTCFullYear(year!, month! - 1, day);
                if (calendar.getUTCMonth() !== month! - 1 || calendar.getUTCDate() !== day) invalid('date calendar');
            } else scalar(value, filter.column);
            if (
                typeof value === 'string' &&
                (Array.from(value).length > 1000 ||
                    (['contains', 'startsWith', 'endsWith'].includes(node.operator) && !value.length))
            )
                invalid('text operand');
        }
    }
    visit(root, rule, 1);
}

/** Restore a shareable view, falling back to initial state/defaults for invalid or outdated links. */
export function readTableState<C extends Capabilities>(
    capabilities: C,
    search: URLSearchParams,
    options: { key?: string; initialState?: Partial<TableState<C>> } = {},
): TableState<C> {
    const fallback = normalizeTableState(capabilities, options.initialState ?? {});
    const key = options.key ?? 'table.' + capabilities.name;
    const encoded = search.get(key);
    if (encoded !== null) {
        try {
            if (search.getAll(key).length !== 1 || [...search.keys()].some((name) => name.startsWith(key + '[')))
                invalid('ambiguous URL');
            if (new TextEncoder().encode(encoded).length > 16384) invalid('URL size');
            const value = JSON.parse(encoded) as {
                version: unknown;
                contract?: unknown;
                state: Partial<TableState<C>>;
            };
            keys(value, capabilities.version === 3 ? ['version', 'contract', 'state'] : ['version', 'state']);
            if (
                value.version !== capabilities.version ||
                (value.version === 3 && value.contract !== (capabilities.stateContract ?? capabilities.contract)) ||
                !value.state ||
                Array.isArray(value.state) ||
                typeof value.state !== 'object'
            )
                invalid('URL state');
            return normalizeTableState(capabilities, { ...fallback, ...value.state });
        } catch {
            // URLs are untrusted and can outlive the capabilities that produced them.
        }
    }
    return fallback;
}

/** Shallow precedence: descriptor defaults, application/saved defaults, explicit fields (including null/[]). */
export function normalizeTableState<C extends Capabilities>(
    capabilities: C,
    overrides: Partial<TableState<C>> = {},
): TableState<C> {
    keys(overrides, ['filter', 'sort', 'groupBy', 'expanded']);
    const state: TableState<C> = {
        filter: null,
        sort: capabilities.defaults.sort,
        groupBy: capabilities.defaults.groupBy,
        expanded: [],
        ...overrides,
    };
    validateState(capabilities, state);
    return structuredClone(state);
}

/** Copy search parameters with this table's view; leave other tables and parameters untouched. */
export function writeTableState<C extends Capabilities>(
    capabilities: C,
    search: URLSearchParams,
    state: TableState<C>,
    options: { key?: string } = {},
): URLSearchParams {
    keys(state, ['filter', 'sort', 'groupBy', 'expanded']);
    validateState(capabilities, state);
    const next = new URLSearchParams(search);
    const { filter, sort, groupBy, expanded } = state;
    const encoded = JSON.stringify({
        version: capabilities.version,
        ...(capabilities.version === 3 ? { contract: capabilities.stateContract ?? capabilities.contract } : {}),
        state: { filter, sort, groupBy, expanded },
    });
    if (new TextEncoder().encode(encoded).length > 16384) invalid('URL size');
    const key = options.key ?? 'table.' + capabilities.name;
    // oxlint-disable-next-line unicorn/no-useless-spread -- Snapshot before deleting bracket variants.
    for (const name of [...next.keys()]) if (name.startsWith(key + '[')) next.delete(name);
    next.set(key, encoded);
    return next;
}

// One structural bridge for generated native queries; server capabilities remain authoritative.
interface Expressions {
    cmp(field: string, op: string, value: unknown): unknown;
    cmpLit(left: unknown, op: string, right: unknown): unknown;
    and(...conditions: unknown[]): unknown;
    or(...conditions: unknown[]): unknown;
    exists(field: string, callback: (query: NativeQuery) => NativeQuery): unknown;
}
interface NativeQuery {
    where(field: string, op: string, value: unknown): NativeQuery;
    where(expression: (builder: Expressions) => unknown): NativeQuery;
    related(field: string, callback: (query: NativeQuery) => NativeQuery): NativeQuery;
    orderBy(field: string, direction: 'asc' | 'desc'): NativeQuery;
    limit(window: number): NativeQuery;
}
export type TableRelationScopes = Readonly<Record<string, (query: NativeQuery) => NativeQuery>>;
function scoped(query: NativeQuery, path: string, scopes: TableRelationScopes) {
    return Object.hasOwn(scopes, path) ? scopes[path]!(query) : query;
}
function expression(
    builder: Expressions,
    scope: FilterScope,
    node: FilterNode,
    path: string[],
    scopes: TableRelationScopes,
): unknown {
    if (node.kind === 'relation') {
        const relation = scope.relations![node.field]!;
        const next = [...path, node.field];
        const exists = (refine: (q: NativeQuery) => NativeQuery) =>
            builder.exists(node.field, (q) => refine(scoped(q, next.join('.'), scopes)));
        switch (node.operator) {
            case 'some':
                return exists((q) => q.where((eb) => expression(eb, relation, node.filter, next, scopes)));
            case 'has':
                return exists((q) => q.where(relation.key, '=', node.value));
            case 'any':
                return exists((q) => q.where(relation.key, 'IN', node.value));
            case 'all':
                return builder.and(
                    ...Array.from(new Set(node.value), (id) => exists((q) => q.where(relation.key, '=', id))),
                );
        }
    }
    if (node.kind !== 'condition')
        return builder[node.kind](...node.children.map((child) => expression(builder, scope, child, path, scopes)));
    const filter = scope.filters[node.field]!;
    const operations: Record<string, string> = {
        equals: '=',
        notEquals: '!=',
        in: 'IN',
        gt: '>',
        gte: '>=',
        lt: '<',
        lte: '<=',
        isNull: 'IS',
        isNotNull: 'IS NOT',
    };
    let value: unknown = node.value ?? null;
    if (filter.kind === 'date' && value !== null) {
        const date = (item: unknown) => encodeDate(new Date(item as string), filter.column.dateFormat);
        value = Array.isArray(value) ? value.map(date) : date(value);
    }
    const operation = operations[node.operator] ?? 'LIKE';
    if (operation === 'LIKE') {
        const escaped = String(value).replace(/[\\%_]/g, '\\$&');
        value = (node.operator === 'startsWith' ? '' : '%') + escaped + (node.operator === 'endsWith' ? '' : '%');
    }
    return builder.cmp(filter.field, operation, value);
}

function relatedColumns(query: NativeQuery, capabilities: Capabilities, scopes: TableRelationScopes): NativeQuery {
    for (const [field, relation] of Object.entries(capabilities.relations ?? {})) {
        if (!relation.lookup) continue;
        const limit = relation.lookup.limit;
        if (!Number.isSafeInteger(limit) || limit < 1 || limit > 100) invalid('lookup limit');
        const visible = (q: NativeQuery) => scoped(q, field, scopes);
        query = relation.junction
            ? projectJunction(query, relation.junction, limit, visible)
            : query.related(field, (q) =>
                  visible(q)
                      .orderBy(relation.key, 'asc')
                      .limit(relation.cardinality === 'one' ? 1 : limit + 1),
              );
    }
    return query;
}
export function applyTableQuery<Q, C extends Capabilities>(
    base: Q,
    input: TableQueryInput<C>,
    capabilities: C,
    scopes: TableRelationScopes = {},
): Q {
    validateState(capabilities, { ...input, expanded: [] });
    if (input.path.length !== input.groupBy.length) invalid('complete leaf path required');
    if (input['__refresh'] !== undefined && input['__row'] === undefined) invalid('identity refresh');
    let query = base as unknown as NativeQuery;
    if (input['__row'] !== undefined) {
        if (
            !Array.isArray(input['__row']) ||
            input['__row'].length !== capabilities.key.length ||
            input.filter !== null ||
            input.groupBy.length ||
            input.sort.length ||
            input['__window'] !== 1
        )
            invalid('identity read');
        capabilities.key.forEach((field, index) => {
            const value = input['__row']![index];
            if (value === null) invalid('identity value');
            scalar(value, capabilities.columns[field]!);
            query = query.where(field, '=', value);
        });
    }
    if (input.filter) query = query.where((eb) => expression(eb, capabilities, input.filter as FilterNode, [], scopes));
    query = relatedColumns(query, capabilities, scopes);
    input.path.forEach((part, index) => {
        if (part.field !== input.groupBy[index]!.field) invalid('group path');
        const field = capabilities.groups[part.field]!.field;
        scalar(part.value, capabilities.columns[field]!);
        query = query.where(field, part.value === null ? 'IS' : '=', part.value);
    });
    const sorts = [
        ...input.sort,
        ...capabilities.key
            .filter((field) => !input.sort.some((sort) => sort.field === field))
            .map((field) => ({ field, direction: 'asc' as const })),
    ];
    for (const sort of sorts) query = query.orderBy(sort.field, sort.direction);
    const window = input['__window'] ?? capabilities.pageSize + 1;
    if (!Number.isInteger(window) || window < 1 || window > (capabilities.maxWindow ?? 10000)) invalid('window');
    return query.limit(window) as unknown as Q;
}

export interface CountState {
    readonly value: number | undefined;
    readonly observedAt: string | undefined;
    readonly pending: boolean;
    readonly stale: boolean;
    readonly error: Error | undefined;
}
export type TableItem<Row> =
    | { kind: 'group'; id: string; path: GroupPath; depth: number; label: string; expanded: boolean; count: CountState }
    | { kind: 'row'; id: string; groupId: string | null; row: Row }
    | { kind: 'loading' | 'load-more' | 'empty'; id: string; groupId: string | null }
    | { kind: 'error'; id: string; groupId: string | null; error: Error };
export interface MetadataResponse {
    version: Capabilities['version'];
    requestKey: string;
    observedAt: string;
    totalCount: number;
    directories: readonly {
        path: GroupPath;
        groups: readonly { id: string; value: Scalar; label: string; count: number }[];
        nextCursor: string | null;
    }[];
}
export class TableTransportError extends Error {
    constructor(public readonly status: number) {
        super(
            status === 409
                ? 'Table contract changed. Reload the application.'
                : `Table metadata request failed (${status}).`,
        );
    }
}
export async function readMetadata<C extends Capabilities>(
    capabilities: C,
    state: TableState<C>,
    directories: readonly { path: GroupPath; after: string | null }[],
    signal: AbortSignal,
): Promise<MetadataResponse> {
    const csrf =
        typeof document === 'undefined'
            ? undefined
            : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const xsrf =
        typeof document === 'undefined'
            ? undefined
            : document.cookie
                  .split('; ')
                  .find((value) => value.startsWith('XSRF-TOKEN='))
                  ?.slice(11);
    const response = await fetch(capabilities.metadataURL, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        signal,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        body: JSON.stringify({
            version: capabilities.version,
            contract: capabilities.contract,
            filter: state.filter,
            groupBy: state.groupBy,
            directories,
        }),
    });
    if (!response.ok) throw new TableTransportError(response.status);
    return (await response.json()) as MetadataResponse;
}
