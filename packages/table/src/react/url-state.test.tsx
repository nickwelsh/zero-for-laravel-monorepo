// @vitest-environment jsdom
import { act, cleanup, renderHook } from '@testing-library/react';
import { renderToString } from 'react-dom/server';
import { StrictMode } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import tasks from '../../../../tests/interop/table.generated';
import { groupId, readTableState, writeTableState, type TableState } from '../index';
import { useDataTable } from './index';
import { useDataTable as useInertiaDataTable } from '../inertia';
import { createPageURLState, usePageURLState } from '../../../client/src/react/url-state';
import { inertiaURLAdapter } from '../../../client/src/react/inertia-url';
import { browserURLAdapter, tableStateCodec } from './url-state';

const inertia = vi.hoisted(() => ({
    pageURL: '/tasks',
    events: new Map<string, Set<(event: { detail: { page: { url: string }; visitId?: string } }) => void>>(),
    writes: [] as { url: string; history: 'replace' | 'push'; finish: () => void }[],
    zero: { context: { user_id: 'alice' } },
}));
vi.mock('@nickwelsh/zero-for-laravel/react', () => ({ useZero: () => inertia.zero, useData: vi.fn() }));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ url: inertia.pageURL }),
    router: {
        replace: (visit: { url: string; onFinish?: (visit: unknown) => void }) =>
            inertia.writes.push({ url: visit.url, history: 'replace', finish: () => visit.onFinish?.(visit) }),
        push: (visit: { url: string; onFinish?: (visit: unknown) => void }) =>
            inertia.writes.push({ url: visit.url, history: 'push', finish: () => visit.onFinish?.(visit) }),
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
    for (const [index, write] of inertia.writes.entries()) {
        act(() => {
            window.history[write.history === 'push' ? 'pushState' : 'replaceState']({}, '', write.url);
            inertia.pageURL = write.url;
            inertia.events
                .get('clientVisit')
                ?.forEach((callback) => callback({ detail: { page: { url: write.url }, visitId: String(index) } }));
            write.finish();
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

it('coordinates two controlled sources, debounced writes, defaults and history restoration', async () => {
    const page = createPageURLState(browserURLAdapter, {
        people: tableStateCodec(capabilities, { key: 'table.people', initialState }),
        companies: tableStateCodec(capabilities, { key: 'table.companies', initialState: { groupBy: ['done'] } }),
    });
    const view = renderHook(() => {
        const state = usePageURLState(page);
        return {
            people: useDataTable(tasks, { state: state.people, onStateChange: (next) => page.set('people', next) }),
            companies: useDataTable(tasks, {
                state: state.companies,
                onStateChange: (next) => page.set('companies', next, { history: 'push' }),
            }),
        };
    });
    vi.useFakeTimers();
    act(() => {
        page.set('people', (previous) => ({ ...previous, filter }), { debounceMs: 250 });
        page.set('companies', (previous) => ({ ...previous, sort }), { history: 'push' });
    });
    expect(view.result.current.people.state.filter).toEqual(filter);
    expect(view.result.current.companies.state.sort).toEqual(sort);
    expect(new URL(window.location.href).searchParams.has('table.people')).toBe(false);
    act(() => {
        vi.advanceTimersByTime(250);
    });
    expect(
        readTableState(capabilities, new URL(window.location.href).searchParams, { key: 'table.people' }).filter,
    ).toEqual(filter);
    expect(view.result.current.companies.state.sort).toEqual(sort);
    act(() => page.reset('people', 'push'));
    expect(view.result.current.people.state).toEqual(initialState);
    expect(view.result.current.companies.state.sort).toEqual(sort);
    expect(new URL(window.location.href).searchParams.get('other')).toBe('keep');
    vi.useRealTimers();
    await act(async () => {
        const moved = new Promise<void>((resolve) =>
            window.addEventListener('popstate', () => resolve(), { once: true }),
        );
        window.history.back();
        await moved;
    });
    expect(view.result.current.people.state.filter).toEqual(filter);
    await act(async () => {
        const moved = new Promise<void>((resolve) =>
            window.addEventListener('popstate', () => resolve(), { once: true }),
        );
        window.history.forward();
        await moved;
    });
    expect(view.result.current.people.state).toEqual(initialState);
    vi.useFakeTimers();
    act(() => page.set('people', (previous) => ({ ...previous, filter }), { debounceMs: 250 }));
    act(() => {
        window.history.replaceState({}, '', '/tasks?other=restored');
        window.dispatchEvent(new PopStateEvent('popstate'));
        vi.advanceTimersByTime(300);
    });
    expect(view.result.current.people.state).toEqual(initialState);
    expect(view.result.current.companies.state.groupBy).toEqual(['done']);
    expect(window.location.search).toBe('?other=restored');
    vi.useRealTimers();
});

it('composes a page coordinator with independent Inertia table writes before router acknowledgement', () => {
    const page = createPageURLState(inertiaURLAdapter, { people: tableStateCodec(capabilities, { key: 'people' }) });
    const view = renderHook(() => {
        const state = usePageURLState(page);
        return { state, second: useInertiaDataTable(tasks, { urlState: { key: 'second' } }) };
    });
    act(() => {
        page.set('people', (previous) => ({ ...previous, filter }), { history: 'push' });
        view.result.current.second.setSort(sort);
    });
    const last = inertia.writes.at(-1)!;
    expect(last.history).toBe('replace');
    expect(
        readTableState(capabilities, new URL(last.url, window.location.href).searchParams, { key: 'people' }).filter,
    ).toEqual(filter);
    expect(view.result.current.state.people.filter).toEqual(filter);
    expect(view.result.current.second.state.sort).toEqual(sort);
});

it('uses PHP initial inputs only for their request URL, not stale props remounted by Inertia history', () => {
    const codecs = { people: tableStateCodec(capabilities, { key: 'people', initialState }) };
    const initial = { url: '/tasks?other=keep', state: { people: { ...initialState, filter } } };
    const direct = createPageURLState(browserURLAdapter, codecs, initial);
    expect(direct.getSnapshot().people.filter).toEqual(filter);
    const search = writeTableState(capabilities, new URLSearchParams(), { ...initialState, sort }, { key: 'people' });
    window.history.replaceState({}, '', '/tasks?' + search.toString());
    const restored = createPageURLState(browserURLAdapter, codecs, initial);
    expect(restored.getSnapshot().people).toEqual({ ...initialState, sort });
});

it.each(['browser', 'inertia'] as const)('preserves a debounced search across unrelated %s writes', (kind) => {
    vi.useFakeTimers();
    try {
        const adapter = kind === 'browser' ? browserURLAdapter : inertiaURLAdapter;
        const page = createPageURLState(adapter, { people: tableStateCodec(capabilities, { key: 'people' }) });
        const view = renderHook(() => usePageURLState(page));
        act(() => page.set('people', (previous) => ({ ...previous, filter }), { debounceMs: 250 }));
        act(() => adapter.navigate('/tasks?other=changed#rows', 'push'));
        expect(view.result.current.people.filter).toEqual(filter);
        act(() => {
            vi.advanceTimersByTime(250);
        });
        const search = new URL(adapter.getURL()).searchParams;
        expect(readTableState(capabilities, search, { key: 'people' }).filter).toEqual(filter);
        expect(search.get('other')).toBe('changed');
        expect(new URL(adapter.getURL()).hash).toBe('#rows');
        const restoredURL = adapter.getURL();
        act(() => {
            page.set('people', (previous) => ({ ...previous, filter: null }), { debounceMs: 250 });
            window.history.replaceState({}, '', restoredURL);
            if (kind === 'browser') window.dispatchEvent(new PopStateEvent('popstate'));
            else
                inertia.events
                    .get('navigate')
                    ?.forEach((callback) => callback({ detail: { page: { url: restoredURL } } }));
            vi.advanceTimersByTime(300);
        });
        expect(view.result.current.people.filter).toEqual(filter);
        expect(adapter.getURL()).toBe(restoredURL);
        view.unmount();
    } finally {
        vi.useRealTimers();
    }
});

it('keeps the latest repeated Inertia URL and pending search through every queued acknowledgement', () => {
    vi.useFakeTimers();
    try {
        const page = createPageURLState(inertiaURLAdapter, {
            people: tableStateCodec(capabilities, { key: 'people', initialState }),
        });
        const view = renderHook(() => usePageURLState(page));
        act(() => {
            page.set('people', { ...initialState, sort }, { history: 'push' });
            page.set('people', initialState);
            page.set('people', { ...initialState, sort }, { history: 'push' });
            page.set('people', (previous) => ({ ...previous, filter }), { debounceMs: 250 });
        });
        const latestURL = inertiaURLAdapter.getURL();
        for (const [index, write] of [...inertia.writes].entries()) {
            act(() => {
                window.history.replaceState({}, '', write.url);
                const event = { detail: { page: { url: write.url }, visitId: String(index) } };
                if (write.history === 'push') inertia.events.get('navigate')?.forEach((callback) => callback(event));
                inertia.events.get('clientVisit')?.forEach((callback) => callback(event));
                write.finish();
            });
            expect(inertiaURLAdapter.getURL()).toBe(latestURL);
            expect(view.result.current.people).toEqual({ ...initialState, sort, filter });
        }
        act(() => {
            vi.advanceTimersByTime(250);
        });
        expect(
            readTableState(capabilities, new URL(inertiaURLAdapter.getURL()).searchParams, { key: 'people' }),
        ).toEqual({ ...initialState, sort, filter });
        // A real history restoration also discards an outstanding URL write.
        act(() => {
            window.history.replaceState({}, '', latestURL);
            inertia.events.get('navigate')?.forEach((callback) => callback({ detail: { page: { url: latestURL } } }));
            vi.advanceTimersByTime(250);
        });
        expect(view.result.current.people).toEqual({ ...initialState, sort });
        view.unmount();
    } finally {
        vi.useRealTimers();
    }
});
