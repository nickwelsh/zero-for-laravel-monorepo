// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { useEffect } from 'react';
import { definitions } from '../../../../tests/interop/generated';
import { createTable, groupId, queryKey, type GroupPath, type TableQueryInput } from '../index';
import { DataTable, useDataTable, type TableSession } from './index';

const native = vi.hoisted(() => ({
    zero: { context: { user_id: 'alice' } },
    rows: new Map<string, readonly object[]>(),
    empty: [] as readonly object[],
    complete: { type: 'complete' },
}));
vi.mock('@rocicorp/zero/react', () => ({
    useZero: () => native.zero,
    useConnectionState: () => ({ name: 'connected' }),
    useQuery: (request: { args: TableQueryInput }) => [
        native.rows.get(queryKey(request.args)) ?? native.empty,
        native.complete,
    ],
}));
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
let table: TableSession<Row, typeof capabilities>;
function Harness() {
    const session = useDataTable(descriptor);
    useEffect(() => {
        table = session;
    }, [session]);
    return (
        <DataTable.Root table={session}>
            <output>{session.items.map((item) => (item.kind === 'row' ? item.row.title : item.kind)).join('|')}</output>
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
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
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
    act(() => {
        for (const leaf of leaves) table.expand(groupId(leaf));
    });
    await waitFor(() => expect(table.items.filter((item) => item.kind === 'row')).toHaveLength(2));
    expect(table.diagnostics.activeLeaves).toBe(8);
    expect(table.state.expanded).toHaveLength(9);
    const more = table.items.find((item) => item.kind === 'load-more' && item.groupId === groupId(leaves[0]!))!;
    act(() => table.loadMore(more.id));
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
