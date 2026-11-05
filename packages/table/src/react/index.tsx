/* oxlint-disable unicorn/no-array-sort -- ES2022 support; only newly allocated arrays are sorted. */
import { memo, useEffect, useLayoutEffect, useMemo, useSyncExternalStore, type ReactNode } from 'react';
import { useData, useZero, type Data } from '@nickwelsh/zero-for-laravel/react';
import { readTableMetadata } from './metadata';
import { SessionContext, Viewport, Group, Row } from './viewport';
import { Cell, EditingStatus } from './cell';
export { useTableCell } from './cell';
export type { CellEditorProps } from './cell';
export { useTableViewport } from './viewport';
export type { ViewportOptions } from './viewport';
import {
    groupId,
    queryKey,
    TableTransportError,
    validateState,
    parseEditorValue,
    type ColumnDefinition,
    type CellChange,
    type CellSubmitResult,
    type Capabilities,
    type CountState,
    type GroupPath,
    type MetadataResponse,
    type Selection,
    type TableDescriptor,
    type TableItem,
    type TableState,
} from '../index';

type StateOptions<C extends Capabilities> =
    | { initialState?: Partial<TableState<C>>; state?: never; onStateChange?: never }
    | { state: TableState<C>; onStateChange(next: TableState<C>): void; initialState?: never };
export interface EditingOptions<Row> {
    enabled: boolean;
    canEditRow?: (row: Row) => boolean;
    canEditColumn?: (column: ColumnDefinition<Row>) => boolean;
    canEditCell?: (row: Row, column: ColumnDefinition<Row>) => boolean;
    submit: (change: CellChange<Row>) => Promise<CellSubmitResult>;
    renderEditor?: (cell: ReturnType<TableSession<Row>['cell']>) => ReactNode;
}
export interface PreloadOptions {
    /** Budget across all currently subscribed row windows, including their extra-row probes. */
    maxRows?: number;
    /** Stop background growth when more than this many leaf queries are subscribed. */
    maxQueries?: number;
    batchSize?: number;
}
export type TableOptions<C extends Capabilities, Row = unknown> = StateOptions<C> & {
    columns?: readonly (Omit<ColumnDefinition<Row>, 'query' | 'field'> & {
        field?: keyof C['columns'] & keyof Row & string;
        query?: {
            filter?: keyof C['filters'] & string;
            sort?: C['sorts'][number];
            group?: keyof C['groups'] & string;
        };
    })[];
    editing?: EditingOptions<Row>;
    /** Opt-in background growth for expanded leaves. Defaults: 1,000 rows, 8 queries, batches of 100. */
    preload?: boolean | PreloadOptions;
    /** Hover/focus intent for collapsed groups. Defaults to first-page loading. */
    hover?: false | 'load' | 'preload';
};
type Draft<Row> = {
    rowId: string;
    columnId: string;
    row: Row;
    data: Data<readonly Row[]> | undefined;
    refresh: string | undefined;
    originalValue: unknown;
    value: unknown;
    status: 'editing' | 'pending' | 'invalid' | 'rejected' | 'unconfirmed';
    errors: Readonly<Record<string, readonly string[]>>;
    error: Error | undefined;
};
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
    private windowScope: string;
    focusedItem: string | undefined;
    private draft: Draft<Row> | undefined;
    private editingNotice: { outcome: CellSubmitResult['status'] } | undefined;
    private saved: { rowId: string; columnId: string; warning: Error | undefined } | undefined;
    get editingRowId() {
        return this.draft?.rowId;
    }
    get editingSelection() {
        if (!this.draft) return undefined;
        const row = this.draft.row as Record<string, string | number | boolean>;
        return this.descriptor.rows({
            filter: null,
            sort: [],
            groupBy: [],
            path: [],
            __window: 1,
            __row: this.descriptor.capabilities.key.map((field) => row[field]!),
            ...(this.draft.refresh ? { __refresh: this.draft.refresh } : {}),
        });
    }
    get editingVisibility() {
        const draft = this.draft;
        const notice = this.editingNotice;
        if (draft ? this.items.some((item) => item.kind === 'row' && item.id === draft.rowId) : !notice)
            return undefined;
        return {
            status: !draft
                ? ('unavailable' as const)
                : draft.status === 'pending'
                  ? ('pending' as const)
                  : draft.data?.error
                    ? ('error' as const)
                    : ('loading' as const),
            outcome:
                notice?.outcome ??
                (draft?.status === 'invalid' || draft?.status === 'rejected' || draft?.status === 'unconfirmed'
                    ? draft.status
                    : undefined),
            canCancel: draft?.status !== 'pending',
            retry: () => {
                if (draft && this.draft === draft) draft.data?.retry();
            },
            cancel: () => {
                if (draft ? this.draft !== draft || draft.status === 'pending' : this.editingNotice !== notice) return;
                this.draft = undefined;
                this.editingNotice = undefined;
                this.emit();
            },
        };
    }
    get columns() {
        return this.options.columns ?? [];
    }
    get renderEditor() {
        return this.options.editing?.renderEditor;
    }
    private findRow(id: string): Row | undefined {
        for (const leaf of this.leaves)
            for (const row of leaf.data?.data ?? []) if (this.descriptor.rowId(row) === id) return row;
        return undefined;
    }
    private draftRow(): Row | undefined {
        const draft = this.draft;
        if (!draft) return undefined;
        if (draft.data?.complete && !draft.data.error)
            return draft.data.data.find((row) => this.descriptor.rowId(row) === draft.rowId);
        return this.findRow(draft.rowId);
    }
    cell(rowId: string, columnId: string) {
        const column = this.columns.find((candidate) => candidate.id === columnId);
        if (!column) throw new Error('Unknown table column: ' + columnId);
        // Flattened rows already carry their identity. Do not serialize every
        // loaded row's primary key again for each rendered cell on every scroll.
        const item = this.items.find((candidate) => candidate.kind === 'row' && candidate.id === rowId);
        const row =
            item?.kind === 'row' ? item.row : this.draft?.rowId === rowId ? this.draftRow() : this.findRow(rowId);
        const draft =
            row !== undefined && this.draft?.rowId === rowId && this.draft.columnId === columnId
                ? this.draft
                : undefined;
        const value =
            row === undefined
                ? undefined
                : column.field
                  ? (row as Record<string, unknown>)[column.field]
                  : column.value?.(row);
        const settings = this.options.editing;
        const editable = !!(
            this.allowed &&
            row !== undefined &&
            column.field &&
            column.editor &&
            settings?.enabled &&
            ((column.editor.kind !== 'custom' && column.editor.kind !== 'relation') || settings.renderEditor) &&
            (settings.canEditRow?.(row) ?? true) &&
            (settings.canEditColumn?.(column) ?? true) &&
            (settings.canEditCell?.(row, column) ?? true)
        );
        return {
            rowId,
            columnId,
            row,
            column,
            value,
            editable,
            active: draft !== undefined,
            draft: draft?.value,
            status: draft?.status,
            errors: draft?.errors ?? {},
            error: draft?.error,
            warning:
                row !== undefined && this.saved?.rowId === rowId && this.saved.columnId === columnId
                    ? this.saved.warning
                    : undefined,
            dirty: !!draft && queryKey(draft.value) !== queryKey(draft.originalValue),
            conflict: !!draft && queryKey(value) !== queryKey(draft.originalValue),
            begin: () => {
                const latest = this.cell(rowId, columnId);
                if (!latest.editable || latest.row === undefined || this.editingNotice?.outcome === 'unconfirmed')
                    return false;
                if (this.draft) {
                    if (this.draft.rowId === rowId && this.draft.columnId === columnId) return true;
                    if (
                        this.draft.status === 'pending' ||
                        this.draft.status === 'unconfirmed' ||
                        queryKey(this.draft.value) !== queryKey(this.draft.originalValue)
                    )
                        return false;
                }
                this.draft = {
                    rowId,
                    columnId,
                    row: latest.row,
                    data: undefined,
                    refresh: undefined,
                    originalValue: latest.value,
                    value: latest.value,
                    status: 'editing',
                    errors: {},
                    error: undefined,
                };
                this.focusedItem = rowId;
                this.saved = undefined;
                this.editingNotice = undefined;
                this.emit();
                return true;
            },
            setDraft: (nextValue: unknown) => {
                const current = this.draft;
                if (
                    !this.cell(rowId, columnId).editable ||
                    !current ||
                    current.rowId !== rowId ||
                    current.columnId !== columnId ||
                    current.status === 'pending' ||
                    current.status === 'unconfirmed'
                )
                    return;
                current.value = nextValue;
                current.status = 'editing';
                current.errors = {};
                current.error = undefined;
                this.emit();
            },
            cancel: () => {
                if (
                    this.draft?.rowId === rowId &&
                    this.draft.columnId === columnId &&
                    this.draft.status !== 'pending'
                ) {
                    this.draft = undefined;
                    this.emit();
                }
            },
            submit: () => this.submitCell(rowId, columnId),
        };
    }
    private async submitCell(rowId: string, columnId: string): Promise<void> {
        const draft = this.draft;
        const cell = this.cell(rowId, columnId);
        if (
            !draft ||
            !cell.active ||
            !cell.editable ||
            draft.status === 'pending' ||
            draft.status === 'unconfirmed' ||
            !cell.column.field ||
            !cell.column.editor
        )
            return;
        let parsed: ReturnType<typeof parseEditorValue>;
        let patch: Readonly<Record<string, unknown>>;
        try {
            parsed = parseEditorValue(cell.column.editor, draft.value);
            patch = cell.column.editor.patch?.(parsed.value) ?? { [cell.column.field]: parsed.value };
        } catch (error) {
            draft.status = 'invalid';
            draft.errors = { [cell.column.field]: [errorOf(error).message] };
            this.emit();
            return;
        }
        const fields = cell.column.editor.fields ?? [cell.column.field];
        const invalidPatch =
            !Object.hasOwn(patch, cell.column.field) ||
            Object.entries(patch).some(([field, value]) => {
                const contract = this.descriptor.capabilities.columns[field];
                if (!fields.includes(field) || !contract) return true;
                if (value === null && !contract.nullable) return true;
                if (value !== null && contract.date && (!(value instanceof Date) || !Number.isFinite(value.getTime())))
                    return true;
                if (
                    value !== null &&
                    !contract.date &&
                    contract.type !== 'json' &&
                    (typeof value !== contract.type || (typeof value === 'number' && !Number.isFinite(value)))
                )
                    return true;
                if (value !== null && contract.enum && !contract.enum.includes(value as string | number)) return true;
                const columns = this.columns.filter((column) => column.field === field);
                return (
                    !columns.length ||
                    columns.some(
                        (column) =>
                            !(this.options.editing!.canEditColumn?.(column) ?? true) ||
                            !(this.options.editing!.canEditCell?.(cell.row!, column) ?? true),
                    )
                );
            });
        if (invalidPatch)
            parsed.errors = [...parsed.errors, 'The editor returned an invalid or read-only field patch.'];
        if (parsed.errors.length) {
            draft.status = 'invalid';
            draft.errors = { [cell.column.field]: parsed.errors };
            this.emit();
            return;
        }
        draft.status = 'pending';
        draft.errors = {};
        draft.error = undefined;
        this.emit();
        let result: CellSubmitResult;
        try {
            result = await this.options.editing!.submit({
                row: cell.row!,
                rowId,
                columnId,
                field: cell.column.field,
                originalValue: draft.originalValue,
                value: patch[cell.column.field],
                patch,
            } as CellChange<Row>);
        } catch (error) {
            result = { status: 'unconfirmed', error: errorOf(error) };
        }
        // An authorization change can destroy the draft while its write is still settling.
        if (this.draft !== draft || !this.allowed) return;
        if (result.status === 'accepted') {
            const visible = this.draftRow() !== undefined;
            this.saved = visible ? { rowId, columnId, warning: result.warning } : undefined;
            this.editingNotice = visible ? undefined : { outcome: result.status };
            this.draft = undefined;
            this.refreshCounts();
        } else if (result.status === 'invalid') {
            draft.status = 'invalid';
            draft.errors = result.errors;
        } else {
            draft.status = result.status;
            draft.error = result.error;
        }
        if (result.status !== 'accepted') {
            // Empty optimistic results are not a committed visibility decision.
            // Observe the write, then require a fresh read before clearing it.
            draft.refresh = crypto.randomUUID();
            draft.data = undefined;
        }
        this.emit();
    }
    focusItem = (id: string | undefined) => {
        if (this.focusedItem !== id) {
            this.focusedItem = id;
            this.emit();
        }
    };
    get state(): TableState<C> {
        return this.currentState;
    }
    items: readonly TableItem<Row>[] = [];
    count: CountState = initialCount();
    leaves: readonly Leaf<Row>[] = [];
    private directories = new Map<string, Directory>();
    private windows = new Map<string, Leaf<Row>>();
    private pendingMore = new Set<string>();
    private listeners = new Set<() => void>();
    private revision = 0;
    private running = false;
    private allowed = true;
    private epoch = 0;
    private request: AbortController | undefined;
    private queued = false;
    private rowsQueued = false;
    private preloadTimer: ReturnType<typeof setTimeout> | undefined;
    private rootOwners = 0;
    private intent: string[] = [];
    private viewport: readonly string[] = [];
    private options: TableOptions<C, Row>;
    constructor(
        readonly descriptor: TableDescriptor<Row, C>,
        options: TableOptions<C, Row> = {},
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
        this.windowScope = queryKey([this.state.filter, this.state.sort, this.state.groupBy]);
        validateState(descriptor.capabilities, this.state);
        this.configure(options);
        this.rebuild();
    }
    subscribe = (listener: () => void) => {
        this.listeners.add(listener);
        return () => {
            this.listeners.delete(listener);
        };
    };
    snapshot = () => this.revision;
    private emit(demandOnly = false) {
        if (this.rebuild(demandOnly)) {
            this.revision++;
            this.listeners.forEach((listener) => listener());
        }
        this.schedulePreload();
    }
    configure(options: TableOptions<C, Row>, syncState = true) {
        if (typeof options.preload === 'object')
            for (const value of Object.values(options.preload))
                if (value !== undefined && (!Number.isSafeInteger(value) || value < 1))
                    throw new RangeError('Preload budgets and batchSize must be positive integers.');
        const ids = new Set<string>();
        for (const column of options.columns ?? []) {
            if (ids.has(column.id)) throw new Error('Duplicate table column: ' + column.id);
            ids.add(column.id);
            if (column.field && !Object.hasOwn(this.descriptor.capabilities.columns, column.field))
                throw new Error('Unknown column field: ' + column.field);
            if (column.editor) {
                if (!column.field) throw new Error('Display columns cannot define editors.');
                for (const field of column.editor.fields ?? [column.field])
                    if (!Object.hasOwn(this.descriptor.capabilities.columns, field))
                        throw new Error('Unknown editor patch field: ' + field);
            }
            for (const [kind, alias] of Object.entries(column.query ?? {})) {
                const capabilities = this.descriptor.capabilities;
                if (
                    kind === 'sort'
                        ? !capabilities.sorts.includes(alias)
                        : !Object.hasOwn(kind === 'filter' ? capabilities.filters : capabilities.groups, alias)
                )
                    throw new Error('Unknown column query alias: ' + alias);
            }
        }
        this.options = options;
        if (syncState && options.state && queryKey(options.state) !== queryKey(this.state))
            this.applyState(options.state);
        if (syncState) {
            if (options.hover === false && this.intent.length) {
                this.intent = [];
                this.emit(true);
            }
            this.cancelPreload();
            this.schedulePreload();
        }
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
        const scope = queryKey([this.state.filter, this.state.sort, this.state.groupBy]);
        if (scope !== this.windowScope) {
            this.pendingMore.clear();
            this.cancelPreload();
        }
        this.windowScope = scope;
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
        if (this.options.hover === false) return;
        this.intent = [id, ...this.intent.filter((value) => value !== id)].slice(0, 4);
        this.emit(true);
        this.schedule();
    };
    setViewport = (ids: readonly string[]) => {
        if (queryKey(ids) === queryKey(this.viewport)) return;
        this.viewport = ids;
        this.emit(true);
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
        const key = this.windowScope + ':' + groupId(path);
        let leaf = this.windows.get(key);
        if (!leaf) {
            leaf = { key, path, limit: this.descriptor.capabilities.pageSize, data: undefined };
            this.windows.set(key, leaf);
        }
        return leaf;
    }
    private rebuild(demandOnly = false) {
        if (!this.allowed) {
            this.items = [];
            this.leaves = [];
            return true;
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
        const rank = (path: GroupPath) => {
            const ancestors = path.map((_, index) => groupId(path.slice(0, index + 1)));
            const index = priority.findIndex((id) => ancestors.includes(id));
            return index < 0 ? priority.length : index;
        };
        const warm = candidates
            .filter((group) => !expanded.has(group.id))
            .sort((a, b) => rank(a.path) - rank(b.path))
            .slice(0, 4);
        const leaves =
            this.state.groupBy.length === 0
                ? [this.leaf([])]
                : [...candidates.filter((group) => expanded.has(group.id)), ...warm].map((group) =>
                      this.leaf(group.path),
                  );
        // Scrolling/hovering changes query priority, not row contents. If the
        // demanded leaves did not change, keep the flattened items and subscribers idle.
        if (demandOnly && leaves.length === this.leaves.length && leaves.every((leaf, i) => leaf === this.leaves[i]))
            return false;
        this.leaves = leaves;
        const active = new Set(this.leaves);
        for (const leaf of this.windows.values())
            if (!active.has(leaf)) {
                leaf.data = undefined;
                this.pendingMore.delete(leaf.key);
            }
        const items: TableItem<Row>[] = [];
        const draftRow = this.draftRow();
        const rows = (path: GroupPath) => {
            const id = path.length ? groupId(path) : null;
            const leaf = this.leaf(path);
            const data = leaf.data;
            for (const row of data?.data.slice(0, leaf.limit) ?? []) {
                const rowId = this.descriptor.rowId(row);
                if (this.draft?.rowId === rowId && draftRow === undefined) continue;
                items.push({ kind: 'row', id: rowId, groupId: id, row });
            }
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
        // Retain moved drafts only while an independent authorized identity read
        // still supplies their row. The original context is never visibility proof.
        if (
            this.draft &&
            draftRow !== undefined &&
            !items.some((item) => item.kind === 'row' && item.id === this.draft!.rowId)
        )
            items.push({
                kind: 'row',
                id: this.draft.rowId,
                groupId: null,
                row: draftRow,
            });
        this.items = items;
        return true;
    }
    reportEditing(rowId: string, key: string, data: Data<readonly Row[]>) {
        const draft = this.draft;
        if (!draft || draft.rowId !== rowId || this.editingSelection?.key !== key) return;
        const previous = draft.data;
        draft.data = data;
        if (data.error && 'status' in data.error && [401, 403, 404, 419].includes(Number(data.error.status))) {
            this.revoke();
            return;
        }
        if (
            draft.status !== 'pending' &&
            data.complete &&
            !data.error &&
            !data.data.some((row) => this.descriptor.rowId(row) === rowId)
        ) {
            this.editingNotice =
                draft.status === 'invalid' || draft.status === 'rejected' || draft.status === 'unconfirmed'
                    ? { outcome: draft.status }
                    : undefined;
            this.draft = undefined;
            this.saved = undefined;
            if (this.focusedItem === rowId) this.focusedItem = undefined;
        }
        if (
            this.draft !== draft ||
            previous?.data !== data.data ||
            previous?.complete !== data.complete ||
            previous?.status !== data.status ||
            previous?.error?.message !== data.error?.message
        )
            this.emit();
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
        // A scroll-triggered request can arrive before native synchronization.
        // Finish that one continuation even if its sentinel has since unmounted.
        const advanced = data.complete && this.pendingMore.delete(key) && this.grow(leaf);
        if (
            advanced ||
            previous?.data !== data.data ||
            previous?.complete !== data.complete ||
            previous?.status !== data.status ||
            previous?.error?.message !== data.error?.message
        ) {
            // Native view updates can settle hundreds of leaf owners in one commit.
            // Publish their latest results together, not one full flatten per leaf.
            if (this.rowsQueued) return;
            this.rowsQueued = true;
            queueMicrotask(() => {
                this.rowsQueued = false;
                if (this.running && this.allowed) this.emit();
            });
        }
    }
    private grow(leaf: Leaf<Row>, amount = this.descriptor.capabilities.pageSize) {
        if (!leaf.data?.complete || leaf.data.data.length <= leaf.limit || leaf.limit >= 2147483647) return false;
        this.pendingMore.delete(leaf.key);
        leaf.limit = Math.min(2147483647, leaf.limit + amount);
        return true;
    }
    private cancelPreload() {
        clearTimeout(this.preloadTimer);
        this.preloadTimer = undefined;
    }
    private schedulePreload() {
        if (
            this.preloadTimer !== undefined ||
            !this.running ||
            (!this.options.preload && this.options.hover !== 'preload')
        )
            return;
        // Yield between batches and after pointer/keyboard/scroll activity. Never
        // grow background windows while foreground queries or an editor are busy.
        this.preloadTimer = setTimeout(() => {
            this.preloadTimer = undefined;
            if (
                !this.running ||
                document.visibilityState !== 'visible' ||
                !navigator.onLine ||
                this.draft ||
                this.request ||
                this.pendingMore.size ||
                this.leaves.some((leaf) => !leaf.data?.complete || leaf.data.error)
            )
                return;
            const {
                maxRows = 1000,
                maxQueries = 8,
                batchSize = 100,
            } = typeof this.options.preload === 'object' ? this.options.preload : {};
            const remaining = maxRows - this.diagnostics.requestedRows;
            if (remaining <= 0 || this.leaves.length > maxQueries) return;
            const expanded = new Set(this.state.expanded);
            const candidates = this.leaves.filter((leaf) => {
                const open = !leaf.path.length || expanded.has(groupId(leaf.path));
                const hovered = leaf.path.some((_, index) =>
                    this.intent.includes(groupId(leaf.path.slice(0, index + 1))),
                );
                return (
                    ((open && this.options.preload) || (hovered && this.options.hover === 'preload')) &&
                    leaf.data!.data.length > leaf.limit
                );
            });
            // Share the budget rather than letting one large group consume it all.
            const leaf = candidates.sort((a, b) => a.limit - b.limit)[0];
            if (leaf && this.grow(leaf, Math.min(batchSize, remaining))) this.emit();
        }, 150);
    }
    loadMore = (id: string) => {
        const leaf = this.leaves.find((value) => id === 'more:' + value.key);
        if (leaf?.data?.complete) {
            if (this.grow(leaf)) this.emit();
        } else if (leaf && (leaf.data?.data.length ?? 0) >= leaf.limit)
            // Do not queue a second continuation against the old, shorter prefix
            // while its replacement query is still catching up.
            this.pendingMore.add(leaf.key);
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
        this.cancelPreload();
        this.epoch++;
        this.request?.abort();
        this.request = undefined;
        this.directories.clear();
        this.count = initialCount();
        this.draft = undefined;
        this.editingNotice = undefined;
        this.saved = undefined;
        this.focusedItem = undefined;
        this.pendingMore.clear();
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
        const postpone = () => {
            this.cancelPreload();
            this.schedulePreload();
        };
        window.addEventListener('focus', refresh);
        window.addEventListener('online', refresh);
        window.addEventListener('offline', postpone);
        window.addEventListener('pointerdown', postpone, { passive: true });
        window.addEventListener('keydown', postpone, { passive: true });
        window.addEventListener('wheel', postpone, { passive: true });
        window.addEventListener('scroll', postpone, { passive: true, capture: true });
        document.addEventListener('visibilitychange', postpone);
        return () => {
            this.rootOwners--;
            window.removeEventListener('focus', refresh);
            window.removeEventListener('online', refresh);
            window.removeEventListener('offline', postpone);
            window.removeEventListener('pointerdown', postpone);
            window.removeEventListener('keydown', postpone);
            window.removeEventListener('wheel', postpone);
            window.removeEventListener('scroll', postpone, true);
            document.removeEventListener('visibilitychange', postpone);
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
            const result = await readTableMetadata(
                this.descriptor.capabilities,
                this.state,
                pending.map(({ path, after }) => ({ path, after })),
                request.signal,
            );
            if (!this.running || epoch !== this.epoch || request.signal.aborted) return;
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
    options: TableOptions<NoInfer<C>, NoInfer<Row>> = {},
): TableSession<Row, C> {
    'use no memo';
    const zero = useZero();
    const context = queryKey(zero.context);
    // Sessions belong to one native instance and authenticated context.
    // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
    const table = useMemo(() => new TableSession(descriptor, options), [descriptor, zero, context]);
    // Like the virtualizer's setOptions, update render/event configuration without
    // publishing during render. Controlled query state is reconciled after commit.
    table.configure(options, false);
    useLayoutEffect(() => table.configure(options), [table, options]);
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    return table;
}
function LeafOwner<Row, C extends Capabilities>({
    table,
    leaf,
    limit,
}: {
    table: TableSession<Row, C>;
    leaf: Leaf<Row>;
    limit: number;
}) {
    const selection = table.descriptor.rows({
        filter: table.state.filter,
        sort: table.state.sort,
        groupBy: table.state.groupBy,
        path: leaf.path,
        __window: Math.min(2147483647, limit + 1),
    });
    const result = useData(selection);
    useLayoutEffect(() => table.report(leaf.key, result), [table, leaf.key, result]);
    return null;
}
const MemoLeafOwner = memo(LeafOwner) as typeof LeafOwner;
function EditingOwner<Row, C extends Capabilities>({
    table,
    rowId,
    selection,
}: {
    table: TableSession<Row, C>;
    rowId: string;
    selection: Selection<Row>;
}) {
    const result = useData(selection, { ttl: 0 });
    useLayoutEffect(() => table.reportEditing(rowId, selection.key, result), [table, rowId, selection.key, result]);
    return null;
}
// Reports update Root; equivalent freshly constructed selections must not feed
// that render back into the identity query. Native store updates still render it.
const MemoEditingOwner = memo(
    EditingOwner,
    (previous, next) =>
        previous.table === next.table && previous.rowId === next.rowId && previous.selection.key === next.selection.key,
) as typeof EditingOwner;
function Root<Row, C extends Capabilities>({ table, children }: { table: TableSession<Row, C>; children?: ReactNode }) {
    'use no memo';
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    useEffect(() => table.mount(), [table]);
    const editing = table.editingSelection;
    return (
        <SessionContext.Provider value={table}>
            {table.leaves.map((leaf) => (
                <MemoLeafOwner key={leaf.key} table={table} leaf={leaf} limit={leaf.limit} />
            ))}
            {editing && (
                <MemoEditingOwner key={editing.key} table={table} rowId={table.editingRowId!} selection={editing} />
            )}
            {children}
        </SessionContext.Provider>
    );
}
export const DataTable = { Root, Viewport, Group, Row, Cell, EditingStatus };
