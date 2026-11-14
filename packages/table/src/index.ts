/* oxlint-disable unicorn/no-array-sort -- ES2022 support; only newly allocated arrays are sorted. */
import { encodeDate, type Column } from '@nickwelsh/zero-for-laravel';
export { column, editors, parseEditorValue } from './columns';
export type { Editor, ColumnDefinition, CellChange, CellSubmitResult } from './columns';

export type Scalar = string | number | boolean | null;
export type GroupPath = readonly { readonly field: string; readonly value: Scalar }[];
export interface Capabilities {
    readonly version: 2;
    readonly name: string;
    readonly contract: string;
    readonly model: string;
    readonly key: readonly string[];
    readonly columns: Readonly<Record<string, Column>>;
    readonly filters: Readonly<
        Record<
            string,
            {
                readonly field: string;
                readonly kind: string;
                readonly operators: readonly string[];
                readonly column: Column;
            }
        >
    >;
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
    readonly maxGroupDepth: number;
    readonly defaults: { readonly sort: readonly Sort[]; readonly groupBy: readonly Sort[] };
    readonly metadataURL: string;
}
type Value<C extends Column> = C['date'] extends true
    ? string
    : C['enum'] extends readonly (string | number)[]
      ? C['enum'][number]
      : { string: string; number: number; boolean: boolean; json: never }[C['type']];
type Condition<C extends Capabilities> = {
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
export type FilterNode<C extends Capabilities = Capabilities> =
    | Condition<C>
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
export interface TableDescriptor<Row, C extends Capabilities = Capabilities> {
    readonly capabilities: C;
    rows(input: TableQueryInput<C>): Selection<Row>;
    rowId(row: Row): string;
}
export function createTable<C extends Capabilities, S extends Selection<unknown>>(
    capabilities: C,
    rows: (input: TableQueryInput<C>) => S,
): TableDescriptor<ReturnType<S['decode']>[number], C> {
    type Row = ReturnType<S['decode']>[number];
    return Object.freeze({
        capabilities,
        rows: (input: TableQueryInput<C>) => ({ ...rows(input), pageSize: undefined }),
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
    if (capabilities.version !== 2) invalid('unsupported protocol');
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
    let terms = 0;
    function visit(node: FilterNode<C>, depth: number): void {
        if (++terms > 100 || depth > 8) invalid('filter complexity');
        keys(node, node.kind === 'condition' ? ['kind', 'field', 'operator', 'value'] : ['kind', 'children']);
        if (node.kind !== 'condition') {
            if (!['and', 'or'].includes(node.kind) || !Array.isArray(node.children) || !node.children.length)
                invalid('filter group');
            node.children.forEach((child) => visit(child, depth + 1));
            return;
        }
        const filter = Object.hasOwn(capabilities.filters, node.field) ? capabilities.filters[node.field] : undefined;
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
    if (state.filter !== null) visit(state.filter, 1);
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
            const value = JSON.parse(encoded) as { version: unknown; state: Partial<TableState<C>> };
            keys(value, ['version', 'state']);
            if (value.version !== 2 || !value.state || Array.isArray(value.state) || typeof value.state !== 'object')
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
    const encoded = JSON.stringify({ version: 2, state: { filter, sort, groupBy, expanded } });
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
    and(...conditions: unknown[]): unknown;
    or(...conditions: unknown[]): unknown;
}
interface NativeQuery {
    where(field: string, op: string, value: unknown): NativeQuery;
    where(expression: (builder: Expressions) => unknown): NativeQuery;
    orderBy(field: string, direction: 'asc' | 'desc'): NativeQuery;
    limit(window: number): NativeQuery;
}
export function applyTableQuery<Q, C extends Capabilities>(base: Q, input: TableQueryInput<C>, capabilities: C): Q {
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
    if (input.filter)
        query = query.where((builder) => {
            function expression(node: FilterNode<C>): unknown {
                if (node.kind !== 'condition') return builder[node.kind](...node.children.map(expression));
                const filter = capabilities.filters[node.field]!;
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
                    value =
                        (node.operator === 'startsWith' ? '' : '%') +
                        escaped +
                        (node.operator === 'endsWith' ? '' : '%');
                }
                return builder.cmp(filter.field, operation, value);
            }
            return expression(input.filter!);
        });
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
    if (!Number.isInteger(window) || window < 1 || window > 2147483647) invalid('window');
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
    version: 2;
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
            version: 2,
            contract: capabilities.contract,
            filter: state.filter,
            groupBy: state.groupBy,
            directories,
        }),
    });
    if (!response.ok) throw new TableTransportError(response.status);
    return (await response.json()) as MetadataResponse;
}
