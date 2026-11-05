// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { useEffect } from 'react';
import { definitions } from '../../../../tests/interop/generated';
import {
    column,
    editors,
    createTable,
    groupId,
    queryKey,
    type GroupPath,
    type TableQueryInput,
    type CellSubmitResult,
} from '../index';
import { DataTable, useDataTable, type TableSession, type TableOptions } from './index';

const native = vi.hoisted(() => ({
    zero: { context: { user_id: 'alice' } },
    rows: new Map<string, readonly object[]>(),
    denied: new Set<string>(),
    failed: new Set<string>(),
    retried: new Set<string>(),
    pending: new Set<string>(),
    listeners: new Set<() => void>(),
    empty: [] as readonly object[],
    complete: { type: 'complete' },
    unknown: { type: 'unknown' },
    copyIdentityResults: false,
}));
vi.mock('@rocicorp/zero/react', async () => {
    const { useRef, useSyncExternalStore } = await import('react');
    return {
        useZero: () => native.zero,
        useConnectionState: () => ({ name: 'connected' }),
        useQuery: (request: { args: TableQueryInput }) => {
            const key = queryKey({ ...request.args, __refresh: undefined });
            const cached = useRef<{ rows: readonly object[]; status: string } | undefined>(undefined);
            const snapshot = useSyncExternalStore(
                (listener) => {
                    native.listeners.add(listener);
                    return () => {
                        native.listeners.delete(listener);
                    };
                },
                () => {
                    const rows = native.rows.get(key) ?? native.empty;
                    const status = native.denied.has(key)
                        ? 'denied'
                        : native.failed.has(key)
                          ? 'failed'
                          : native.pending.has(key)
                            ? 'pending'
                            : 'complete';
                    if (cached.current?.rows !== rows || cached.current.status !== status)
                        cached.current = { rows, status };
                    return cached.current;
                },
            );
            return [
                Object.hasOwn(request.args, '__row') && native.copyIdentityResults ? [...snapshot.rows] : snapshot.rows,
                native.denied.has(key)
                    ? { type: 'error', error: { message: 'Denied', details: { status: 403 } } }
                    : native.failed.has(key)
                      ? {
                            type: 'error',
                            error: { message: 'Protected query details', details: {} },
                            retry: () => {
                                native.retried.add(key);
                                native.failed.delete(key);
                                native.rows.set(key, [...(native.rows.get(key) ?? native.empty)]);
                                native.listeners.forEach((listener) => listener());
                            },
                        }
                      : native.pending.has(key)
                        ? native.unknown
                        : native.complete,
            ];
        },
    };
});
const capabilities = {
    version: 1,
    name: 'tasks',
    contract: 'test-contract',
    model: 'Task',
    key: ['id'],
    columns: definitions.Task.columns,
    filters: {
        title: { field: 'title', kind: 'text', operators: ['contains'], column: definitions.Task.columns.title },
    },
    sorts: ['title'],
    groups: {
        done: { field: 'done', relation: null, label: null, model: null },
        list: { field: 'list_id', relation: 'list', label: 'name', model: 'TaskList' },
    },
    pageSize: 2,
    maxGroupDepth: 2,
    defaults: { sort: [], groupBy: ['done', 'list'] },
    metadataURL: '/metadata',
} as const;
type Row = { id: string; title: string; done: boolean; list_id: string | null };
const descriptor = createTable(capabilities, (args) => ({
    key: queryKey(args),
    one: false,
    request: () => ({ args }),
    decode: (raw: unknown) => raw as readonly Row[],
}));
const editingKey = (id: string) =>
    queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 1, __row: [id] });
let table: TableSession<Row, typeof capabilities>;
function Harness({ options = {} }: { options?: TableOptions<typeof capabilities, Row> }) {
    const session = useDataTable(descriptor, options);
    useEffect(() => {
        table = session;
    }, [session]);
    return (
        <DataTable.Root table={session}>
            <DataTable.EditingStatus />
            <output>{session.items.map((item) => (item.kind === 'row' ? item.row.title : item.kind)).join('|')}</output>
            {session.items.flatMap((item) =>
                item.kind === 'row'
                    ? [
                          <DataTable.Row key={item.id} item={item}>
                              {session.columns.map((definition) => (
                                  <DataTable.Cell
                                      key={definition.id}
                                      table={session}
                                      rowId={item.id}
                                      columnId={definition.id}
                                  />
                              ))}
                          </DataTable.Row>,
                      ]
                    : [],
            )}
        </DataTable.Root>
    );
}
function directory(path: GroupPath) {
    const field = path.length ? 'list' : 'done';
    const values = path.length ? Array.from({ length: 8 }, (_, index) => `list-${index}`) : [false, true];
    return {
        path,
        groups: values.map((value) => ({
            id: groupId([...path, { field, value }]),
            value,
            label: String(value),
            count: 9,
        })),
        nextCursor: null,
    };
}
const response = (directories: readonly { path: GroupPath }[], count = 144) =>
    new Response(
        JSON.stringify({
            version: 1,
            requestKey: 'test',
            observedAt: '2026-11-04T12:00:00Z',
            totalCount: count,
            directories: directories.map(({ path }) => directory(path)),
        }),
    );
beforeEach(() => {
    native.rows.clear();
    native.denied.clear();
    native.failed.clear();
    native.retried.clear();
    native.pending.clear();
    native.copyIdentityResults = false;
    vi.stubGlobal(
        'fetch',
        vi.fn((_url: string, options: RequestInit) => {
            const input = JSON.parse(options.body as string) as { directories: { path: GroupPath }[] };
            return Promise.resolve(response(input.directories));
        }),
    );
});
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('preloads bounded batches, supports live opt-out, and leaves foreground growth unrestricted', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const rows: Row[] = Array.from({ length: 9 }, (_, i) => ({
        id: String(i),
        title: `Task ${i}`,
        done: false,
        list_id: null,
    }));
    for (const window of [3, 6, 8, 10, 12])
        native.rows.set(
            queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: window }),
            rows.slice(0, window),
        );
    vi.stubGlobal(
        'fetch',
        vi.fn(() => Promise.resolve(response([], 1))),
    );
    const options = { initialState: { groupBy: [] }, preload: { maxRows: 8, batchSize: 3 } };
    const view = render(<Harness options={options} />);
    await act(async () => {});
    expect(table.diagnostics.requestedRows).toBe(3);
    await act(async () => vi.advanceTimersByTimeAsync(150));
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(5);
    view.rerender(<Harness options={{ ...options, preload: false }} />);
    await act(async () => vi.advanceTimersByTimeAsync(1000));
    expect(table.diagnostics.requestedRows).toBe(6);
    view.rerender(<Harness options={options} />);
    await act(async () => vi.advanceTimersByTimeAsync(1000));
    expect(table.diagnostics.requestedRows).toBe(8);
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(7);
    expect(table.count.value).toBe(1); // A stale low count must not truncate live rows.
    act(() => table.loadMore(table.items.find((item) => item.kind === 'load-more')!.id));
    await act(async () => {});
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(9);
    view.rerender(<Harness options={{ ...options, preload: { maxRows: 12, batchSize: 3 } }} />);
    await act(async () => vi.advanceTimersByTimeAsync(1000));
    expect(table.diagnostics.requestedRows).toBe(10); // Native completion, not the budget, stops this query.
    expect(table.items.some((item) => item.kind === 'load-more')).toBe(false);
});

it('waits for synchronization and quiet, online, visible demand before background growth', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const args = (window: number) => ({ filter: null, sort: [], groupBy: [], path: [], __window: window });
    const rows: Row[] = Array.from({ length: 9 }, (_, i) => ({
        id: String(i),
        title: `Task ${i}`,
        done: false,
        list_id: null,
    }));
    for (const window of [3, 6, 8]) native.rows.set(queryKey(args(window)), rows.slice(0, window));
    native.pending.add(queryKey(args(3)));
    const view = render(<Harness options={{ initialState: { groupBy: [] }, preload: { maxRows: 8, batchSize: 3 } }} />);
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(3);
    await act(async () => {
        native.pending.clear();
        native.rows.set(queryKey(args(3)), rows.slice(0, 3));
        native.listeners.forEach((listener) => listener());
    });
    await act(async () => vi.advanceTimersByTimeAsync(100));
    fireEvent.wheel(window);
    await act(async () => vi.advanceTimersByTimeAsync(100));
    expect(table.diagnostics.requestedRows).toBe(3);
    await act(async () => vi.advanceTimersByTimeAsync(50));
    expect(table.diagnostics.requestedRows).toBe(6);
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(false);
    fireEvent.offline(window);
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(6);
    vi.spyOn(navigator, 'onLine', 'get').mockReturnValue(true);
    const visibility = vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('hidden');
    fireEvent.online(window);
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(6);
    visibility.mockReturnValue('visible');
    fireEvent(document, new Event('visibilitychange'));
    await act(async () => vi.advanceTimersByTimeAsync(150));
    expect(table.diagnostics.requestedRows).toBe(8);
    act(() => table.setFilter({ kind: 'condition', field: 'title', operator: 'contains', value: 'other' }));
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(3);
    expect(table.items.some((item) => item.kind === 'row')).toBe(false);
    view.unmount();
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(0);
    expect(native.listeners.size).toBe(0);
});

it('preloads hovered collapsed leaves within shared query and row budgets without capping expansion', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    const path = [{ field: 'done', value: false }] as const;
    const leaves = Array.from({ length: 8 }, (_, index) => [...path, { field: 'list', value: `list-${index}` }]);
    for (const leaf of leaves)
        for (const window of [3, 6, 8])
            native.rows.set(
                queryKey({ filter: null, sort: [], groupBy: ['done', 'list'], path: leaf, __window: window }),
                Array.from({ length: window }, (_, i) => ({
                    id: `${leaf[1]!.value}-${i}`,
                    title: `Task ${i}`,
                    done: false,
                    list_id: leaf[1]!.value,
                })),
            );
    const options: TableOptions<typeof capabilities, Row> = {
        initialState: { expanded: [groupId(path)] },
        preload: { maxRows: 17, maxQueries: 3, batchSize: 3 },
        hover: false,
    };
    const view = render(<Harness options={options} />);
    await act(async () => {});
    act(() => table.warm(groupId(leaves[6]!)));
    expect(table.leaves.some((leaf) => groupId(leaf.path) === groupId(leaves[6]!))).toBe(false);
    view.rerender(<Harness options={{ ...options, hover: 'load' }} />);
    act(() => table.warm(groupId(leaves[6]!)));
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(12);
    expect(table.leaves.some((leaf) => groupId(leaf.path) === groupId(leaves[6]!))).toBe(true);
    view.rerender(<Harness options={{ ...options, hover: 'preload' }} />);
    await act(async () => vi.advanceTimersByTimeAsync(500));
    expect(table.diagnostics.requestedRows).toBe(12); // Four subscribed leaves exceed maxQueries: 3.
    view.rerender(
        <Harness options={{ ...options, hover: 'preload', preload: { maxRows: 17, maxQueries: 4, batchSize: 3 } }} />,
    );
    await act(async () => vi.advanceTimersByTimeAsync(1000));
    expect(table.diagnostics.requestedRows).toBe(17);
    expect(table.state.expanded).toEqual([groupId(path)]);
    expect(table.items.some((item) => item.kind === 'row')).toBe(false);
    act(() => table.expand(groupId(leaves[6]!)));
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(7);
    act(() => leaves.forEach((leaf) => table.expand(groupId(leaf))));
    await act(async () => {});
    const requested = table.diagnostics.requestedRows;
    expect(table.diagnostics.activeLeaves).toBe(8);
    expect(requested).toBeGreaterThan(17);
    await act(async () => vi.advanceTimersByTimeAsync(1000));
    expect(table.diagnostics.requestedRows).toBe(requested);
});

it('ignores brief pointer passes and warms the focused parent rather than another cached branch', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] });
    render(<Harness />);
    await act(async () => {});
    const groups = table.items.filter((item) => item.kind === 'group');
    const buttons = render(
        <>
            <DataTable.Group table={table} group={groups[0]!} />
            <DataTable.Group table={table} group={groups[1]!} />
        </>,
    );
    const [first, second] = buttons.getAllByRole('button');
    fireEvent.pointerEnter(first!);
    await act(async () => vi.advanceTimersByTimeAsync(100));
    fireEvent.pointerLeave(first!);
    await act(async () => vi.advanceTimersByTimeAsync(200));
    expect(table.diagnostics.warmLeaves).toBe(0);
    fireEvent.pointerEnter(first!);
    await act(async () => vi.advanceTimersByTimeAsync(150));
    expect(table.diagnostics.warmLeaves).toBe(4);
    expect(table.leaves.every((leaf) => leaf.path[0]!.value === false)).toBe(true);
    fireEvent.focus(second!);
    await act(async () => {});
    expect(table.leaves.every((leaf) => leaf.path[0]!.value === true)).toBe(true);
    expect(table.state.expanded).toEqual([]);
});

it('keeps independent open siblings and saved live prefixes across parent collapse without a row cache', async () => {
    const path = [{ field: 'done', value: false }] as const;
    const leaves = Array.from({ length: 8 }, (_, index) => [...path, { field: 'list', value: `list-${index}` }]);
    const args = (leaf: GroupPath, window: number) => ({
        filter: null,
        sort: [],
        groupBy: ['done', 'list'],
        path: leaf,
        __window: window,
    });
    const rows: Row[] = Array.from({ length: 5 }, (_, index) => ({
        id: `r-${index}`,
        title: `Task ${index}`,
        done: false,
        list_id: 'list-0',
    }));
    native.rows.set(queryKey(args(leaves[0]!, 3)), rows.slice(0, 3));
    native.rows.set(queryKey(args(leaves[0]!, 5)), rows);
    render(<Harness />);
    await waitFor(() => expect(table.count.value).toBe(144));
    act(() => table.expand(groupId(path)));
    await waitFor(() =>
        expect(table.items.filter((item) => item.kind === 'group' && item.depth === 1)).toHaveLength(8),
    );
    expect(table.diagnostics.warmLeaves).toBe(4);
    act(() => table.warm(groupId(leaves[7]!)));
    expect(table.leaves.map((leaf) => groupId(leaf.path))).toContain(groupId(leaves[7]!));
    act(() => table.setViewport([groupId(leaves[6]!)]));
    expect(table.leaves.map((leaf) => groupId(leaf.path))).toContain(groupId(leaves[6]!));
    expect(table.diagnostics.warmLeaves).toBe(4);
    act(() => {
        for (const leaf of leaves) table.expand(groupId(leaf));
    });
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(2));
    expect(table.diagnostics.activeLeaves).toBe(8);
    expect(table.state.expanded).toHaveLength(9);
    const more = table.items.find((item) => item.kind === 'load-more' && item.groupId === groupId(leaves[0]!))!;
    act(() => {
        table.loadMore(more.id);
        table.loadMore(more.id);
    });
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(4));
    act(() => table.collapse(groupId(path)));
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(0);
    expect(table.diagnostics.activeLeaves).toBe(8);
    act(() => table.expand(groupId(path)));
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(4);
    expect(table.items.some((item) => item.kind === 'loading')).toBe(false);
    act(() => table.collapse(groupId(leaves[0]!)));
    act(() => table.expand(groupId(leaves[0]!)));
    expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(4);
    // Snapshot counts cannot prove a live prefix is exhausted.
    expect(table.items.some((item) => item.kind === 'load-more')).toBe(true);
});

it('finishes one requested continuation after scrolling away during synchronization', async () => {
    const args = (window: number) => ({ filter: null, sort: [], groupBy: [], path: [], __window: window });
    const rows: Row[] = Array.from({ length: 7 }, (_, i) => ({
        id: String(i),
        title: `Task ${i}`,
        done: false,
        list_id: null,
    }));
    native.rows.set(queryKey(args(3)), rows.slice(0, 3));
    native.rows.set(queryKey(args(5)), rows.slice(0, 5));
    native.pending.add(queryKey(args(3)));
    render(<Harness options={{ initialState: { groupBy: [] } }} />);
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(2));
    const more = table.items.find((item) => item.kind === 'load-more')!;
    act(() => {
        table.setViewport([more.id]);
        table.loadMore(more.id);
        table.setViewport([]);
    });
    expect(table.diagnostics.requestedRows).toBe(3);
    await act(async () => {
        native.pending.delete(queryKey(args(3)));
        native.rows.set(queryKey(args(3)), rows.slice(0, 3));
        native.listeners.forEach((listener) => listener());
    });
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(4));
    // Finishing an offscreen request must not drain the rest of the group.
    expect(table.diagnostics.requestedRows).toBe(5);
});

it('rejects late metadata after identity changes and clears all protected data on denied access', async () => {
    let deliver: (value: Response) => void = () => {};
    const fetch = vi.fn(
        (_url: string, _options: RequestInit) =>
            new Promise<Response>((resolve) => {
                deliver = resolve;
            }),
    );
    vi.stubGlobal('fetch', fetch);
    render(<Harness />);
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(1));
    const late = deliver;
    act(() => table.setFilter({ kind: 'condition', field: 'title', operator: 'contains', value: 'new' }));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
    await act(async () => {
        late(response([{ path: [] }], 999));
    });
    expect(table.count.value).toBeUndefined();
    await act(async () => {
        deliver(response([{ path: [] }], 7));
    });
    expect(table.count.value).toBe(7);
    act(() => table.expand(groupId([{ field: 'done', value: false }])));
    await waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
    await act(async () => {
        deliver(new Response('{}', { status: 403 }));
    });
    expect(table.items).toHaveLength(0);
    expect(table.count.value).toBeUndefined();
    expect(table.diagnostics.loadedRows).toBe(0);
});

it('never publishes queued sibling rows after a live access denial', async () => {
    const path = [{ field: 'done', value: false }] as const;
    const args = (list: string) => ({
        filter: null,
        sort: [],
        groupBy: ['done', 'list'],
        path: [...path, { field: 'list', value: list }],
        __window: 3,
    });
    native.rows.set(queryKey(args('list-0')), [
        { id: 'private', title: 'Private row', done: false, list_id: 'list-0' },
    ]);
    native.denied.add(queryKey(args('list-1')));
    render(<Harness />);
    await waitFor(() => expect(table.count.value).toBe(144));
    const exposed: string[] = [];
    const unsubscribe = table.subscribe(() => {
        for (const item of table.items) if (item.kind === 'row') exposed.push(item.row.title);
    });
    await act(async () => {
        table.expand(groupId(path));
        table.expand(groupId(args('list-0').path));
        table.expand(groupId(args('list-1').path));
    });
    await waitFor(() => expect(table.count.value).toBeUndefined());
    await act(async () => {
        await Promise.resolve();
    });
    expect(table.items).toHaveLength(0);
    expect(table.diagnostics.loadedRows).toBe(0);
    expect(exposed).toEqual([]);
    unsubscribe();
});

it('bounds the rendered window independently of loaded rows and preserves keyboard cell focus', async () => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(52);
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const large = createTable({ ...capabilities, pageSize: 1000, defaults: { sort: [], groupBy: [] } }, (args) => ({
        key: queryKey(args),
        one: false,
        request: () => ({ args }),
        decode: (raw: unknown) => raw as readonly Row[],
    }));
    const rows = Array.from({ length: 1000 }, (_, i) => ({
        id: String(i),
        title: `Row ${i}`,
        done: false,
        list_id: null,
    }));
    native.rows.set(queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 1001 }), rows);
    function Grid() {
        const session = useDataTable(large);
        return (
            <DataTable.Root table={session}>
                <DataTable.Viewport table={session} scrollElement="window" aria-label="Tasks">
                    {(item) =>
                        item.kind === 'row' ? (
                            <DataTable.Row item={item}>
                                {/* oxlint-disable-next-line jsx-a11y/prefer-tag-over-role -- ARIA grid, not a native table. */}
                                <div role="gridcell" tabIndex={-1}>
                                    {item.row.title}
                                </div>
                            </DataTable.Row>
                        ) : (
                            item.kind
                        )
                    }
                </DataTable.Viewport>
            </DataTable.Root>
        );
    }
    const screen = render(<Grid />);
    await waitFor(() => expect(screen.getByText('Row 0')).toBeDefined());
    expect(screen.getAllByRole('row').length).toBeLessThan(80);
    expect(screen.queryByText('Row 999')).toBeNull();
    act(() => screen.getByText('Row 0').focus());
    fireEvent.keyDown(document.activeElement!, { key: 'ArrowDown' });
    await waitFor(() => expect(document.activeElement?.textContent).toBe('Row 1'));
    fireEvent.keyDown(document.activeElement!, { key: 'End' });
    await waitFor(() => expect(document.activeElement?.textContent).toBe('Row 999'));
    expect(screen.getAllByRole('row').length).toBeLessThan(80);
});

it('prefetches beyond rendered overscan at the configured boundary without following pinned rows', async () => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    );
    vi.stubGlobal('innerHeight', 260);
    vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(52);
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {});
    const paged = createTable({ ...capabilities, pageSize: 30, defaults: { sort: [], groupBy: [] } }, (args) => ({
        key: queryKey(args),
        one: false,
        request: () => ({ args }),
        decode: (raw: unknown) => raw as readonly Row[],
    }));
    const rows = Array.from({ length: 65 }, (_, i) => ({
        id: String(i),
        title: `Row ${i}`,
        done: false,
        list_id: null,
    }));
    for (const window of [31, 61, 91])
        native.rows.set(
            queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: window }),
            rows.slice(0, window),
        );
    let session!: TableSession<Row, typeof paged.capabilities>;
    function Grid({ prefetchRows }: { prefetchRows: number }) {
        session = useDataTable(paged);
        return (
            <DataTable.Root table={session}>
                <DataTable.Viewport
                    table={session}
                    scrollElement="window"
                    prefetchRows={prefetchRows}
                    aria-label="Tasks"
                >
                    {(item) =>
                        item.kind === 'row' ? <DataTable.Row item={item}>{item.row.title}</DataTable.Row> : item.kind
                    }
                </DataTable.Viewport>
            </DataTable.Root>
        );
    }
    const screen = render(<Grid prefetchRows={0} />);
    await waitFor(() => expect(session.items.filter((item) => item.kind === 'row')).toHaveLength(30));
    act(() => session.focusItem(session.items.find((item) => item.kind === 'row' && item.row.id === '29')!.id));
    await waitFor(() => expect(screen.getByText('Row 29')).toBeDefined());
    expect(session.diagnostics.requestedRows).toBe(31);
    screen.rerender(<Grid prefetchRows={25} />);
    expect(session.diagnostics.requestedRows).toBe(31);
    screen.rerender(<Grid prefetchRows={26} />);
    await waitFor(() => expect(session.items.filter((item) => item.kind === 'row')).toHaveLength(60));
    expect(session.diagnostics.requestedRows).toBe(61);
    expect(screen.queryByText('load-more')).toBeNull();
    expect(screen.getAllByRole('row').length).toBeLessThan(20);
});

it('ANDs every edit gate, validates locally, consumes IME Enter and waits for authoritative acceptance', async () => {
    native.copyIdentityResults = true;
    const rows: Row[] = [{ id: 'a', title: 'alice@example.com', done: false, list_id: null }];
    native.rows.set(queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 }), rows);
    native.rows.set(editingKey('a'), rows);
    let resolve!: (result: CellSubmitResult) => void;
    const submit = vi.fn(
        () =>
            new Promise<CellSubmitResult>((done) => {
                resolve = done;
            }),
    );
    const options: TableOptions<typeof capabilities, Row> = {
        initialState: { groupBy: [] },
        columns: [
            column.email('title', { header: 'Email', editor: editors.email() }),
            column.boolean('done', { editor: editors.checkbox() }),
        ],
        editing: { enabled: false, submit },
    };
    const screen = render(<Harness options={options} />);
    await waitFor(() => expect(screen.getByRole('gridcell', { name: 'Email' })).toBeDefined());
    const id = descriptor.rowId(rows[0]!);
    expect(table.cell(id, 'title').begin()).toBe(false);
    for (const gate of ['canEditRow', 'canEditColumn', 'canEditCell'] as const) {
        screen.rerender(<Harness options={{ ...options, editing: { enabled: true, submit, [gate]: () => false } }} />);
        expect(table.cell(id, 'title').begin()).toBe(false);
    }
    screen.rerender(<Harness options={{ ...options, editing: { enabled: true, submit } }} />);
    fireEvent.doubleClick(screen.getByRole('gridcell', { name: 'Email' }));
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'not-an-email' } });
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Email' }), { key: 'Enter' });
    expect(screen.getByRole('alert').textContent).toContain('valid email');
    expect(submit).not.toHaveBeenCalled();
    fireEvent.change(screen.getByRole('textbox', { name: 'Email' }), { target: { value: 'new@example.net' } });
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Email' }), { key: 'Enter', isComposing: true });
    expect(submit).not.toHaveBeenCalled();
    // Another cell cannot silently discard this dirty draft.
    expect(table.cell(id, 'done').begin()).toBe(false);
    fireEvent.keyDown(screen.getByRole('textbox', { name: 'Email' }), { key: 'Enter' });
    expect(table.cell(id, 'title').status).toBe('pending');
    expect(submit.mock.calls).toHaveLength(1);
    act(() => table.cell(id, 'title').cancel());
    expect(table.cell(id, 'title').active).toBe(true);
    await act(async () => resolve({ status: 'accepted', warning: new Error('Saved; response unreadable') }));
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.getByText('Saved; response unreadable')).toBeDefined();
});

it('keeps conflicting or moved drafts, preserves rejected and uncertain outcomes, and destroys drafts on revocation', async () => {
    const row: Row = { id: 'a', title: 'Original', done: false, list_id: null };
    const key = queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 });
    native.rows.set(key, [row]);
    native.rows.set(editingKey(row.id), [row]);
    let outcome: CellSubmitResult = { status: 'rejected', error: new Error('Not assignable') };
    const submit = vi.fn(async () => outcome);
    const options: TableOptions<typeof capabilities, Row> = {
        initialState: { groupBy: [] },
        columns: [column.text('title', { editor: editors.text() })],
        editing: { enabled: true, submit },
    };
    const screen = render(<Harness options={options} />);
    await waitFor(() => expect(table.items.some((item) => item.kind === 'row')).toBe(true));
    const id = descriptor.rowId(row);
    act(() => {
        table.cell(id, 'title').begin();
        table.cell(id, 'title').setDraft('My draft');
    });
    native.rows.set(key, [{ ...row, title: 'Remote change' }]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(table.cell(id, 'title').conflict).toBe(true));
    expect(table.cell(id, 'title').draft).toBe('My draft');
    native.rows.set(key, []);
    native.pending.add(editingKey(row.id));
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(table.diagnostics.loadedRows).toBe(0));
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(table.cell(id, 'title')).toMatchObject({ row: undefined, editable: false, active: false, draft: undefined });
    await act(async () => table.cell(id, 'title').submit());
    expect(submit).not.toHaveBeenCalled();
    native.pending.delete(editingKey(row.id));
    native.rows.set(editingKey(row.id), [{ ...row, title: 'Remote change' }]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.getByRole('textbox')).toBeDefined());
    await act(async () => table.cell(id, 'title').submit());
    expect(table.cell(id, 'title').status).toBe('rejected');
    expect(table.cell(id, 'title').draft).toBe('My draft');
    outcome = { status: 'unconfirmed', error: new Error('Connection lost') };
    await act(async () => table.cell(id, 'title').submit());
    await act(async () => table.cell(id, 'title').submit());
    expect(submit).toHaveBeenCalledTimes(2);
    expect(table.cell(id, 'title').status).toBe('unconfirmed');
    const oldCell = table.cell(id, 'title');
    // A successful empty identity read revokes one row, not the whole collection.
    native.rows.set(editingKey(row.id), []);
    const other = { ...row, id: 'b', title: 'Still visible' };
    native.rows.set(key, [other]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(table.editingRowId).toBeUndefined());
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByText('Original')).toBeNull();
    expect(table.cell(id, 'title')).toMatchObject({ row: undefined, editable: false, active: false, draft: undefined });
    expect(table.cell(descriptor.rowId(other), 'title').editable).toBe(true);
    expect(oldCell.begin()).toBe(false);
    vi.stubGlobal(
        'fetch',
        vi.fn(async () => new Response('{}', { status: 403 })),
    );
    act(() => table.refreshCounts());
    await waitFor(() => expect(table.items).toHaveLength(0));
    expect(table.editingRowId).toBeUndefined();
    expect(oldCell.begin()).toBe(false);
});

it('does not restore a revoked draft when an already-submitted write later succeeds', async () => {
    const row: Row = { id: 'a', title: 'Protected', done: false, list_id: null };
    const other = { ...row, id: 'b', title: 'Still visible' };
    const key = queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 });
    native.rows.set(key, [row, other]);
    native.rows.set(editingKey(row.id), [row]);
    let resolve!: (result: CellSubmitResult) => void;
    const submit = vi.fn(
        () =>
            new Promise<CellSubmitResult>((done) => {
                resolve = done;
            }),
    );
    const screen = render(
        <Harness
            options={{
                initialState: { groupBy: [] },
                columns: [column.text('title', { editor: editors.text() })],
                editing: { enabled: true, submit },
            }}
        />,
    );
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(2));
    const id = descriptor.rowId(row);
    act(() => {
        table.cell(id, 'title').begin();
        table.cell(id, 'title').setDraft('Changed');
    });
    let pending!: Promise<void>;
    act(() => {
        pending = table.cell(id, 'title').submit();
    });
    expect(table.cell(id, 'title').status).toBe('pending');
    native.rows.set(key, [other]);
    native.rows.set(editingKey(row.id), []);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.queryByRole('textbox')).toBeNull());
    expect(table.editingVisibility).toMatchObject({ status: 'pending', canCancel: false });
    expect(screen.getByRole('button', { name: 'Cancel edit' }).hasAttribute('disabled')).toBe(true);
    await act(async () => {
        resolve({ status: 'accepted', warning: new Error('Protected response') });
        await pending;
    });
    expect(table.editingRowId).toBeUndefined();
    expect(table.editingVisibility).toMatchObject({ status: 'unavailable', outcome: 'accepted' });
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.queryByText('Protected response')).toBeNull();
    expect(table.cell(id, 'title')).toMatchObject({
        row: undefined,
        active: false,
        draft: undefined,
        warning: undefined,
    });
    expect(table.cell(descriptor.rowId(other), 'title').editable).toBe(true);
});

it('retains authoritative validation after optimistic disappearance and rechecks visibility before restoring the editor', async () => {
    const row: Row = { id: 'a', title: 'Original', done: false, list_id: null };
    const key = queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 });
    native.rows.set(key, [row]);
    native.rows.set(editingKey(row.id), [row]);
    let resolve!: (result: CellSubmitResult) => void;
    const submit = vi.fn(
        () =>
            new Promise<CellSubmitResult>((done) => {
                resolve = done;
            }),
    );
    const screen = render(
        <Harness
            options={{
                initialState: { groupBy: [] },
                columns: [column.text('title', { editor: editors.text() })],
                editing: { enabled: true, submit },
            }}
        />,
    );
    await waitFor(() => expect(table.items.some((item) => item.kind === 'row')).toBe(true));
    const id = descriptor.rowId(row);
    act(() => {
        table.cell(id, 'title').begin();
        table.cell(id, 'title').setDraft('Hidden');
    });
    let pending!: Promise<void>;
    act(() => {
        pending = table.cell(id, 'title').submit();
    });
    native.rows.set(key, []);
    native.rows.set(editingKey(row.id), []);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.queryByRole('textbox')).toBeNull());
    native.pending.add(editingKey(row.id));
    await act(async () => {
        resolve({ status: 'invalid', errors: { title: ['Reserved title'] } });
        await pending;
    });
    expect(table.editingVisibility).toMatchObject({ status: 'loading', outcome: 'invalid' });
    expect(screen.queryByText('Reserved title')).toBeNull();
    native.pending.delete(editingKey(row.id));
    native.rows.set(editingKey(row.id), [row]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.getByRole('textbox')).toBeDefined());
    expect(table.cell(id, 'title')).toMatchObject({
        active: true,
        draft: 'Hidden',
        status: 'invalid',
        errors: { title: ['Reserved title'] },
    });
    expect(screen.getByRole('alert').textContent).toBe('Reserved title');
    expect(submit).toHaveBeenCalledTimes(1);
});

it('offers redacted retry and cancel when an offscreen dirty draft loses its identity query', async () => {
    const row: Row = { id: 'a', title: 'Protected original', done: false, list_id: null };
    const other = { ...row, id: 'b', title: 'Other row' };
    const key = queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 });
    native.rows.set(key, [row, other]);
    native.rows.set(editingKey(row.id), [row]);
    const screen = render(
        <Harness
            options={{
                initialState: { groupBy: [] },
                columns: [column.text('title', { editor: editors.text() })],
                editing: { enabled: true, submit: async () => ({ status: 'accepted' }) },
            }}
        />,
    );
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(2));
    const id = descriptor.rowId(row);
    act(() => {
        table.cell(id, 'title').begin();
        table.cell(id, 'title').setDraft('Protected draft');
    });
    native.rows.set(key, [other]);
    native.failed.add(editingKey(row.id));
    native.rows.set(editingKey(row.id), [{ ...row }]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Retry visibility' })).toBeDefined());
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(screen.getByRole('alert').textContent).not.toContain('Protected');
    expect(table.cell(descriptor.rowId(other), 'title').begin()).toBe(false);
    fireEvent.click(screen.getByRole('button', { name: 'Retry visibility' }));
    await waitFor(() => expect(screen.getByRole('textbox')).toBeDefined());
    expect(native.retried.has(editingKey(row.id))).toBe(true);
    expect(table.cell(id, 'title').draft).toBe('Protected draft');
    native.failed.add(editingKey(row.id));
    native.rows.set(editingKey(row.id), [{ ...row }]);
    act(() => native.listeners.forEach((listener) => listener()));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Cancel edit' })).toBeDefined());
    fireEvent.click(screen.getByRole('button', { name: 'Cancel edit' }));
    expect(table.editingRowId).toBeUndefined();
    act(() => {
        expect(table.cell(descriptor.rowId(other), 'title').begin()).toBe(true);
    });
});

it('validates every declared composite patch field and applies secondary column gates', async () => {
    const row: Row = { id: 'a', title: 'Original', done: false, list_id: null };
    native.rows.set(queryKey({ filter: null, sort: [], groupBy: [], path: [], __window: 3 }), [row]);
    native.rows.set(editingKey(row.id), [row]);
    const changes: unknown[] = [];
    const options: TableOptions<typeof capabilities, Row> = {
        initialState: { groupBy: [] },
        columns: [
            column.text('title', {
                editor: editors.custom({ fields: ['title', 'done'], patch: (value) => ({ title: value, done: true }) }),
            }),
            column.boolean('done'),
        ],
        editing: {
            enabled: true,
            renderEditor: () => null,
            canEditColumn: (definition) => definition.field !== 'done',
            submit: async (change) => {
                changes.push(change.patch);
                return { status: 'accepted' };
            },
        },
    };
    const screen = render(<Harness options={options} />);
    await waitFor(() => expect(table.items.some((item) => item.kind === 'row')).toBe(true));
    const id = descriptor.rowId(row);
    act(() => {
        table.cell(id, 'title').begin();
        table.cell(id, 'title').setDraft('New');
    });
    await act(async () => table.cell(id, 'title').submit());
    expect(table.cell(id, 'title').status).toBe('invalid');
    expect(changes).toEqual([]);
    screen.rerender(<Harness options={{ ...options, columns: options.columns!.slice(0, 1) }} />);
    await act(async () => table.cell(id, 'title').submit());
    expect(table.cell(id, 'title').status).toBe('invalid');
    expect(changes).toEqual([]);
    screen.rerender(
        <Harness
            options={{
                ...options,
                columns: [
                    column.text('title', {
                        editor: editors.custom({ patch: (value) => ({ title: value, done: true }) }),
                    }),
                ],
                editing: { ...options.editing!, canEditColumn: () => true },
            }}
        />,
    );
    await act(async () => table.cell(id, 'title').submit());
    expect(table.cell(id, 'title').status).toBe('invalid');
    expect(changes).toEqual([]);
    screen.rerender(
        <Harness
            options={{
                ...options,
                columns: [
                    column.text('title', {
                        editor: editors.custom({
                            patch: () => {
                                throw new Error('Invalid composite');
                            },
                        }),
                    }),
                ],
            }}
        />,
    );
    await act(async () => table.cell(id, 'title').submit());
    expect(table.cell(id, 'title').errors).toEqual({ title: ['Invalid composite'] });
    expect(changes).toEqual([]);
    screen.rerender(<Harness options={{ ...options, editing: { ...options.editing!, canEditColumn: () => true } }} />);
    await act(async () => table.cell(id, 'title').submit());
    expect(changes).toEqual([{ title: 'New', done: true }]);
});
