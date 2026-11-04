/* oxlint-disable unicorn/no-array-sort -- ES2022 support; only newly allocated arrays are sorted. */
import { Fragment, useEffect, useLayoutEffect, useMemo, useSyncExternalStore, type ReactNode } from 'react';
import { useData, useZero, type Data } from '@nickwelsh/zero-for-laravel/react';
import {
    groupId,
    queryKey,
    readMetadata,
    TableTransportError,
    validateState,
    type Capabilities,
    type CountState,
    type GroupPath,
    type MetadataResponse,
    type TableDescriptor,
    type TableItem,
    type TableState,
} from '../index';

type StateOptions<C extends Capabilities> =
    | { initialState?: Partial<TableState<C>>; state?: never; onStateChange?: never }
    | { state: TableState<C>; onStateChange(next: TableState<C>): void; initialState?: never };
export type TableOptions<C extends Capabilities> = StateOptions<C>;
type SnapshotGroup = MetadataResponse['directories'][number]['groups'][number] & { observedAt: string };
type Directory = {
    path: GroupPath;
    groups: readonly SnapshotGroup[];
    nextCursor: string | null;
    after: string | null;
    loaded: boolean;
    pending: boolean;
    error: Error | undefined;
    refresh: { target: number; groups: readonly SnapshotGroup[] } | undefined;
};
export interface Leaf<Row> {
    readonly key: string;
    readonly path: GroupPath;
    limit: number;
    data: Data<readonly Row[]> | undefined;
}
const initialCount = (): CountState => ({
    value: undefined,
    observedAt: undefined,
    pending: false,
    stale: true,
    error: undefined,
});
const errorOf = (error: unknown) => (error instanceof Error ? error : new Error(String(error)));

/** Owns query demand and metadata, never a second copy of Zero's canonical rows. */
export class TableSession<Row, C extends Capabilities = Capabilities> {
    private currentState: TableState<C>;
    get state(): TableState<C> {
        return this.currentState;
    }
    items: readonly TableItem<Row>[] = [];
    count: CountState = initialCount();
    leaves: readonly Leaf<Row>[] = [];
    private directories = new Map<string, Directory>();
    private windows = new Map<string, Leaf<Row>>();
    private listeners = new Set<() => void>();
    private revision = 0;
    private running = false;
    private allowed = true;
    private epoch = 0;
    private request: AbortController | undefined;
    private queued = false;
    private rootOwners = 0;
    private intent: string[] = [];
    private viewport: readonly string[] = [];
    private options: TableOptions<C>;
    constructor(
        readonly descriptor: TableDescriptor<Row, C>,
        options: TableOptions<C> = {},
    ) {
        this.options = options;
        this.currentState = structuredClone(
            options.state ?? {
                filter: null,
                sort: descriptor.capabilities.defaults.sort,
                groupBy: descriptor.capabilities.defaults.groupBy,
                expanded: [],
                ...options.initialState,
            },
        );
        validateState(descriptor.capabilities, this.state);
        this.rebuild();
    }
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    snapshot = () => this.revision;
    private emit() {
        this.rebuild();
        this.revision++;
        this.listeners.forEach((listener) => listener());
    }
    configure(options: TableOptions<C>) {
        this.options = options;
        if (options.state && queryKey(options.state) !== queryKey(this.state)) this.applyState(options.state);
    }
    private change(patch: Partial<TableState<C>>) {
        const next = { ...this.state, ...patch };
        validateState(this.descriptor.capabilities, next);
        if (this.options.onStateChange) this.options.onStateChange(next);
        else this.applyState(next);
    }
    private applyState(next: TableState<C>) {
        validateState(this.descriptor.capabilities, next);
        const metadataChanged =
            queryKey([next.filter, next.groupBy]) !== queryKey([this.state.filter, this.state.groupBy]);
        this.currentState = structuredClone(next);
        if (metadataChanged) {
            this.epoch++;
            this.request?.abort();
            this.request = undefined;
            this.directories.clear();
            this.count = initialCount();
        }
        this.emit();
        this.schedule();
    }
    setFilter = (filter: TableState<C>['filter']) => this.change({ filter });
    setSort = (sort: TableState<C>['sort']) => this.change({ sort });
    setGroupBy = (groupBy: TableState<C>['groupBy']) => this.change({ groupBy });
    expand = (id: string) => {
        if (!this.state.expanded.includes(id)) this.change({ expanded: [...this.state.expanded, id] });
    };
    collapse = (id: string) => this.change({ expanded: this.state.expanded.filter((value) => value !== id) });
    toggle = (id: string) => (this.state.expanded.includes(id) ? this.collapse(id) : this.expand(id));
    warm = (id: string) => {
        this.intent = [id, ...this.intent.filter((value) => value !== id)].slice(0, 4);
        this.emit();
        this.schedule();
    };
    setViewport = (ids: readonly string[]) => {
        if (queryKey(ids) === queryKey(this.viewport)) return;
        this.viewport = ids;
        this.emit();
        this.schedule();
    };
    private directory(path: GroupPath): Directory {
        const id = groupId(path);
        let directory = this.directories.get(id);
        if (!directory) {
            directory = {
                path,
                groups: [],
                nextCursor: null,
                after: null,
                loaded: false,
                pending: false,
                error: undefined,
                refresh: undefined,
            };
            this.directories.set(id, directory);
        }
        return directory;
    }
    private leaf(path: GroupPath): Leaf<Row> {
        const key = queryKey([this.state.filter, this.state.sort, this.state.groupBy, path]);
        let leaf = this.windows.get(key);
        if (!leaf) {
            leaf = { key, path, limit: this.descriptor.capabilities.pageSize, data: undefined };
            this.windows.set(key, leaf);
        }
        return leaf;
    }
    private rebuild() {
        if (!this.allowed) {
            this.items = [];
            this.leaves = [];
            return;
        }
        const expanded = new Set(this.state.expanded);
        const known = [...this.directories.values()].flatMap((directory) =>
            directory.groups.map((group) => ({
                ...group,
                path: [...directory.path, { field: this.state.groupBy[directory.path.length]!, value: group.value }],
            })),
        );
        for (const group of known)
            if (
                (expanded.has(group.id) || this.intent.includes(group.id)) &&
                group.path.length < this.state.groupBy.length
            )
                this.directory(group.path);
        const candidates = known.filter((group) => group.path.length === this.state.groupBy.length);
        const priority = [...this.intent, ...this.viewport];
        const rank = (id: string) => (priority.includes(id) ? priority.indexOf(id) : priority.length);
        const warm = candidates
            .filter((group) => !expanded.has(group.id))
            .sort((a, b) => rank(a.id) - rank(b.id))
            .slice(0, 4);
        this.leaves =
            this.state.groupBy.length === 0
                ? [this.leaf([])]
                : [...candidates.filter((group) => expanded.has(group.id)), ...warm].map((group) =>
                      this.leaf(group.path),
                  );
        const active = new Set(this.leaves);
        for (const leaf of this.windows.values()) if (!active.has(leaf)) leaf.data = undefined;
        const items: TableItem<Row>[] = [];
        const rows = (path: GroupPath) => {
            const id = path.length ? groupId(path) : null;
            const leaf = this.leaf(path);
            const data = leaf.data;
            for (const row of data?.data.slice(0, leaf.limit) ?? [])
                items.push({ kind: 'row', id: this.descriptor.rowId(row), groupId: id, row });
            if (data?.error) items.push({ kind: 'error', id: 'e:' + leaf.key, groupId: id, error: data.error });
            else if (!data?.data.length && !data?.complete)
                items.push({ kind: 'loading', id: 'l:' + leaf.key, groupId: id });
            else if (data?.complete && !data.data.length)
                items.push({ kind: 'empty', id: 'empty:' + leaf.key, groupId: id });
            else if (!data?.complete || data.data.length > leaf.limit)
                items.push({ kind: 'load-more', id: 'more:' + leaf.key, groupId: id });
        };
        const directoryItems = (path: GroupPath) => {
            const directory = this.directory(path);
            for (const group of directory.groups) {
                const child = [...path, { field: this.state.groupBy[path.length]!, value: group.value }];
                const open = expanded.has(group.id);
                items.push({
                    kind: 'group',
                    id: group.id,
                    path: child,
                    depth: path.length,
                    label: group.label,
                    expanded: open,
                    count: {
                        value: group.count,
                        observedAt: group.observedAt,
                        pending: directory.pending,
                        stale: directory.refresh !== undefined || directory.error !== undefined,
                        error: directory.error,
                    },
                });
                if (open) {
                    if (child.length === this.state.groupBy.length) rows(child);
                    else directoryItems(child);
                }
            }
            const id = path.length ? groupId(path) : null;
            if (directory.error)
                items.push({ kind: 'error', id: 'de:' + groupId(path), groupId: id, error: directory.error });
            else if (!directory.loaded) items.push({ kind: 'loading', id: 'dl:' + groupId(path), groupId: id });
            else if (directory.nextCursor !== null)
                items.push({ kind: 'load-more', id: 'dm:' + groupId(path), groupId: id });
            else if (!directory.groups.length) items.push({ kind: 'empty', id: 'empty:' + groupId(path), groupId: id });
        };
        if (this.state.groupBy.length) directoryItems([]);
        else rows([]);
        this.items = items;
    }
    report(key: string, data: Data<readonly Row[]>) {
        const leaf = this.windows.get(key);
        if (!leaf || !this.leaves.includes(leaf)) return;
        const previous = leaf.data;
        leaf.data = data;
        if (data.error && 'status' in data.error && [401, 403, 404, 419].includes(Number(data.error.status))) {
            this.revoke();
            return;
        }
        if (
            previous?.data !== data.data ||
            previous?.complete !== data.complete ||
            previous?.status !== data.status ||
            previous?.error?.message !== data.error?.message
        )
            this.emit();
    }
    loadMore = (id: string) => {
        const leaf = this.leaves.find((value) => id === 'more:' + value.key);
        if (leaf?.data?.complete && leaf.data.data.length > leaf.limit && leaf.limit < 2147483647) {
            leaf.limit = Math.min(2147483647, leaf.limit + this.descriptor.capabilities.pageSize);
            this.emit();
        }
        for (const directory of this.directories.values())
            if (id === 'dm:' + groupId(directory.path) && !directory.pending && directory.nextCursor) {
                directory.after = directory.nextCursor;
                directory.loaded = false;
                this.emit();
                this.schedule();
            }
    };
    retry = (id: string) => {
        const leaf = this.leaves.find((value) => id === 'e:' + value.key);
        leaf?.data?.retry();
        for (const directory of this.directories.values())
            if (id === 'de:' + groupId(directory.path)) {
                directory.error = undefined;
                directory.loaded = false;
            }
        this.emit();
        this.schedule();
    };
    refreshCounts = () => {
        if (!this.running) return;
        this.epoch++;
        this.request?.abort();
        this.request = undefined;
        this.count = { ...this.count, stale: true, pending: true, error: undefined };
        for (const directory of this.directories.values()) {
            directory.refresh = { target: directory.groups.length, groups: [] };
            directory.after = null;
            directory.pending = false;
            directory.loaded = false;
            directory.error = undefined;
        }
        this.emit();
        this.schedule();
    };
    private revoke() {
        this.allowed = false;
        this.epoch++;
        this.request?.abort();
        this.request = undefined;
        this.directories.clear();
        this.count = initialCount();
        for (const leaf of this.windows.values()) leaf.data = undefined;
        this.running = false;
        this.emit();
    }
    mount() {
        if (++this.rootOwners !== 1) throw new Error('Mount exactly one DataTable.Root per table session.');
        this.allowed = true;
        this.running = true;
        this.emit();
        this.schedule();
        const refresh = () => {
            if (document.visibilityState === 'visible') this.refreshCounts();
        };
        window.addEventListener('focus', refresh);
        window.addEventListener('online', refresh);
        return () => {
            this.rootOwners--;
            window.removeEventListener('focus', refresh);
            window.removeEventListener('online', refresh);
            this.revoke();
        };
    }
    private schedule() {
        if (this.queued || !this.running) return;
        this.queued = true;
        queueMicrotask(() => {
            this.queued = false;
            void this.fetch();
        });
    }
    private async fetch() {
        if (!this.running || this.request) return;
        const pending = [...this.directories.values()]
            .filter((value) => !value.loaded && !value.pending && !value.error)
            .slice(0, 16);
        if (!pending.length && this.count.value !== undefined && !this.count.stale) return;
        if (!pending.length && this.count.error) return;
        const request = new AbortController();
        this.request = request;
        const epoch = this.epoch;
        for (const directory of pending) directory.pending = true;
        this.count = { ...this.count, pending: true };
        this.emit();
        try {
            const result = await readMetadata(
                this.descriptor.capabilities,
                this.state,
                pending.map(({ path, after }) => ({ path, after })),
                request.signal,
            );
            if (!this.running || epoch !== this.epoch || request.signal.aborted) return;
            if (
                result.version !== 1 ||
                !Number.isSafeInteger(result.totalCount) ||
                result.totalCount < 0 ||
                typeof result.observedAt !== 'string' ||
                result.directories.length !== pending.length
            )
                throw new Error('Invalid table metadata response.');
            for (const [index, directory] of pending.entries()) {
                const page = result.directories[index]!;
                if (
                    queryKey(page.path) !== queryKey(directory.path) ||
                    page.groups.length > 50 ||
                    (page.nextCursor !== null && typeof page.nextCursor !== 'string')
                )
                    throw new Error('Invalid table directory response.');
                const field = this.state.groupBy[directory.path.length]!;
                for (const group of page.groups)
                    if (
                        group.id !== groupId([...directory.path, { field, value: group.value }]) ||
                        !Number.isSafeInteger(group.count) ||
                        group.count < 0 ||
                        typeof group.label !== 'string'
                    )
                        throw new Error('Invalid table group response.');
            }
            this.count = {
                value: result.totalCount,
                observedAt: result.observedAt,
                pending: false,
                stale: false,
                error: undefined,
            };
            for (const [index, directory] of pending.entries()) {
                const page = result.directories[index]!;
                const merged = new Map(
                    (directory.refresh?.groups ?? directory.groups).map((group) => [group.id, group]),
                );
                for (const group of page.groups) merged.set(group.id, { ...group, observedAt: result.observedAt });
                const groups = [...merged.values()];
                directory.pending = false;
                directory.error = undefined;
                if (directory.refresh && page.nextCursor && groups.length < directory.refresh.target) {
                    directory.refresh.groups = groups;
                    directory.after = page.nextCursor;
                } else {
                    directory.groups = groups;
                    directory.nextCursor = page.nextCursor;
                    directory.loaded = true;
                    directory.refresh = undefined;
                }
            }
        } catch (error) {
            if (epoch !== this.epoch || request.signal.aborted) return;
            if (error instanceof TableTransportError && [401, 403, 404, 419].includes(error.status)) {
                this.revoke();
                return;
            }
            this.count = { ...this.count, pending: false, stale: true, error: errorOf(error) };
            for (const directory of pending) {
                directory.pending = false;
                directory.error = errorOf(error);
            }
        } finally {
            if (this.request === request) {
                this.request = undefined;
                this.emit();
                this.schedule();
            }
        }
    }
    get diagnostics() {
        const expanded = new Set(this.state.expanded);
        return {
            activeLeaves: this.leaves.filter((leaf) => !leaf.path.length || expanded.has(groupId(leaf.path))).length,
            warmLeaves: this.leaves.filter((leaf) => leaf.path.length && !expanded.has(groupId(leaf.path))).length,
            retainedWindows: this.windows.size,
            requestedRows: this.leaves.reduce((sum, leaf) => sum + leaf.limit + 1, 0),
            loadedRows: this.leaves.reduce((sum, leaf) => sum + (leaf.data?.data.length ?? 0), 0),
            metadataPending: this.request !== undefined,
        };
    }
}

export function useDataTable<Row, C extends Capabilities>(
    descriptor: TableDescriptor<Row, C>,
    options: TableOptions<C> = {},
): TableSession<Row, C> {
    const zero = useZero();
    const context = queryKey(zero.context);
    // Sessions belong to one native instance and authenticated context.
    // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
    const table = useMemo(() => new TableSession(descriptor, options), [descriptor, zero, context]);
    useLayoutEffect(() => table.configure(options), [table, options]);
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    return table;
}
function LeafOwner<Row, C extends Capabilities>({ table, leaf }: { table: TableSession<Row, C>; leaf: Leaf<Row> }) {
    const selection = table.descriptor.rows({
        filter: table.state.filter,
        sort: table.state.sort,
        groupBy: table.state.groupBy,
        path: leaf.path,
        __window: Math.min(2147483647, leaf.limit + 1),
    });
    const result = useData(selection);
    useLayoutEffect(() => table.report(leaf.key, result), [table, leaf.key, result]);
    return null;
}
function Root<Row, C extends Capabilities>({ table, children }: { table: TableSession<Row, C>; children?: ReactNode }) {
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    useEffect(() => table.mount(), [table]);
    return (
        <Fragment>
            {table.leaves.map((leaf) => (
                <LeafOwner key={leaf.key} table={table} leaf={leaf} />
            ))}
            {children}
        </Fragment>
    );
}
export const DataTable = { Root };
