// @vitest-environment jsdom
import { act, cleanup, renderHook } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import tasks from '../../../../tests/interop/table.generated';
import { groupId, readTableState, writeTableState, type TableState } from '../index';
import { useDataTable } from './index';
import { useDataTable as useInertiaDataTable } from '../inertia';

const inertia = vi.hoisted(() => ({
    pageURL: '/tasks',
    events: new Map<string, Set<(event: { detail: { page: { url: string } } }) => void>>(),
    writes: [] as { url: string; history: 'replace' | 'push' }[],
    zero: { context: { user_id: 'alice' } },
}));
vi.mock('@nickwelsh/zero-for-laravel/react', () => ({ useZero: () => inertia.zero, useData: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: inertia.pageURL }),
    router: {
        replace: ({ url }: { url: string }) => inertia.writes.push({ url, history: 'replace' }),
        push: ({ url }: { url: string }) => inertia.writes.push({ url, history: 'push' }),
        on: (name: string, callback: (event: { detail: { page: { url: string } } }) => void) => {
            const listeners = inertia.events.get(name) ?? new Set();
            inertia.events.set(name, listeners);
            listeners.add(callback);
            return () => listeners.delete(callback);
        },
    },
}));
const { capabilities } = tasks;
const initialState: TableState<typeof capabilities> = { filter: null, sort: [], groupBy: [], expanded: [] };
const filter = { kind: 'condition', field: 'title', operator: 'contains', value: 'R&D + café' } as const;
const sort = [{ field: 'title', direction: 'desc' }] as const;

beforeEach(() => {
    window.history.replaceState({ application: 'preserved' }, '', '/tasks?other=keep#rows');
    inertia.pageURL = '/tasks?other=keep#rows';
    inertia.writes.length = 0;
});
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

it('owns URL restoration and synchronous composed updates, preserving unrelated history data', () => {
    const historyLength = window.history.length;
    const options = { urlState: true };
    const view = renderHook(() => useDataTable(tasks, options), { wrapper: StrictMode });
    const expanded = groupId([{ field: 'done', value: false }]);
    act(() => {
        view.result.current.setFilter(filter);
        view.result.current.setSort(sort);
        view.result.current.setGroupBy(['done']);
        view.result.current.expand(expanded);
    });
    const expected = { filter, sort, groupBy: ['done'], expanded: [expanded] };
    expect(view.result.current.state).toEqual(expected);
    expect(readTableState(capabilities, new URL(window.location.href).searchParams)).toEqual(expected);
    expect(window.history.length).toBe(historyLength);
    expect(window.history.state).toEqual({ application: 'preserved' });
    expect(new URL(window.location.href).searchParams.get('other')).toBe('keep');
    expect(window.location.hash).toBe('#rows');
    view.unmount();
    const fresh = renderHook(() => useDataTable(tasks, { urlState: true }));
    expect(fresh.result.current.state).toEqual(expected);
});

it('follows Back/Forward and resets removed or invalid URL state to initial settings', async () => {
    const options = { urlState: { key: 'view', history: 'push' as const }, initialState };
    const view = renderHook(() => useDataTable(tasks, options));
    act(() => view.result.current.setFilter(filter));
    act(() => view.result.current.setSort(sort));
    await act(async () => {
        const moved = new Promise<void>((resolve) =>
            window.addEventListener('popstate', () => resolve(), { once: true }),
        );
        window.history.back();
        await moved;
    });
    expect(view.result.current.state).toEqual({ ...initialState, filter });
    await act(async () => {
        const moved = new Promise<void>((resolve) =>
            window.addEventListener('popstate', () => resolve(), { once: true }),
        );
        window.history.forward();
        await moved;
    });
    expect(view.result.current.state).toEqual({ ...initialState, filter, sort });
    for (const search of ['', '?view=not-json', '?view=null']) {
        act(() => {
            window.history.replaceState({}, '', '/tasks' + search);
            window.dispatchEvent(new PopStateEvent('popstate'));
        });
        expect(view.result.current.state).toEqual(initialState);
    }
});

it('isolates same-descriptor instances by key and leaves local and controlled modes in application ownership', () => {
    const view = renderHook(
        ({ enabled }) => [
            useDataTable(tasks, { urlState: enabled ? { key: 'first' } : false }),
            useDataTable(tasks, { urlState: { key: 'second' } }),
        ],
        { initialProps: { enabled: true } },
    );
    act(() => {
        view.result.current[0]!.setFilter(filter);
        view.result.current[1]!.setSort(sort);
    });
    const search = new URL(window.location.href).searchParams;
    expect(readTableState(capabilities, search, { key: 'first' }).filter).toEqual(filter);
    expect(readTableState(capabilities, search, { key: 'second' }).filter).toBeNull();
    expect(readTableState(capabilities, search, { key: 'second' }).sort).toEqual(sort);
    view.rerender({ enabled: false });
    const url = window.location.href;
    act(() => view.result.current[0]!.setFilter(null));
    expect(window.location.href).toBe(url);

    let next: TableState<typeof capabilities> | undefined;
    const controlled = renderHook(
        ({ state }) =>
            useDataTable(tasks, {
                state,
                onStateChange: (value) => {
                    next = value;
                },
            }),
        {
            initialProps: { state: initialState },
        },
    );
    act(() => controlled.result.current.setFilter(filter));
    expect(controlled.result.current.state).toEqual(initialState);
    expect(next).toEqual({ ...initialState, filter });
    controlled.rerender({ state: next! });
    expect(controlled.result.current.state.filter).toEqual(filter);
    expect(window.location.href).toBe(url);
});

it('merges pending Inertia writes across tables and ignores intermediate router commits', () => {
    const view = renderHook(
        () => [
            useInertiaDataTable(tasks, { urlState: { key: 'first' } }),
            useInertiaDataTable(tasks, { urlState: { key: 'second', history: 'push' } }),
        ],
        { wrapper: StrictMode },
    );
    act(() => {
        view.result.current[0]!.setFilter(filter);
        view.result.current[1]!.setSort(sort);
        view.result.current[0]!.setGroupBy(['done']);
    });
    const first = view.result.current[0]!.state;
    const second = view.result.current[1]!.state;
    expect(first).toEqual({ filter, sort: capabilities.defaults.sort, groupBy: ['done'], expanded: [] });
    expect(second).toEqual({ filter: null, sort, groupBy: capabilities.defaults.groupBy, expanded: [] });
    for (const write of inertia.writes) {
        act(() => {
            window.history[write.history === 'push' ? 'pushState' : 'replaceState']({}, '', write.url);
            inertia.pageURL = write.url;
            inertia.events
                .get('clientVisit')
                ?.forEach((callback) => callback({ detail: { page: { url: write.url } } }));
        });
        expect(view.result.current[0]!.state).toEqual(first);
        expect(view.result.current[1]!.state).toEqual(second);
    }
    const search = new URL(window.location.href).searchParams;
    expect(readTableState(capabilities, search, { key: 'first' })).toEqual(first);
    expect(readTableState(capabilities, search, { key: 'second' })).toEqual(second);
    expect(search.get('other')).toBe('keep');
    act(() => {
        window.history.replaceState({}, '', '/tasks');
        inertia.events.get('navigate')?.forEach((callback) => callback({ detail: { page: { url: '/tasks' } } }));
    });
    expect(view.result.current[0]!.state.filter).toBeNull();
    expect(view.result.current[1]!.state.sort).toEqual(capabilities.defaults.sort);
});

it('restores Inertia URL state during SSR without accessing browser globals', () => {
    const search = writeTableState(capabilities, new URLSearchParams(), { ...initialState, filter, sort });
    inertia.pageURL = '/tasks?' + search.toString();
    vi.stubGlobal('window', undefined);
    function ServerTable() {
        const table = useInertiaDataTable(tasks, { urlState: true });
        return <output>{table.state.filter?.kind === 'condition' ? table.state.filter.value : 'missing'}</output>;
    }
    expect(renderToString(<ServerTable />)).toBe('<output>R&amp;D + café</output>');
});
