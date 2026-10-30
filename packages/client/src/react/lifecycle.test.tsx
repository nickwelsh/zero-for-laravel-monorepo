// @vitest-environment jsdom
import { act, cleanup, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { Task, schema, definitions } from '../../../../tests/interop/generated';
import { createZeroForLaravel } from '../runtime';
import { liveQuery } from '../live-query';
import { useData, type Data } from './data';
import { createInertiaAdapter, useLiveProps } from './inertia';

const native = vi.hoisted(() => ({
    results: new Map<string, readonly [unknown, { type: string; error?: { message: string }; retry?: () => void }]>(),
    zero: { context: { user_id: 'alice' } },
    retry: vi.fn(),
}));
vi.mock('@rocicorp/zero/react', () => ({
    useQuery: (request: { args: unknown } | undefined, options: { enabled?: boolean }) =>
        !request || options.enabled === false
            ? [undefined, { type: 'unknown' }]
            : (native.results.get(JSON.stringify(request.args)) ?? [[], { type: 'unknown' }]),
    useZero: () => native.zero,
    useConnectionState: () => ({ name: 'connected' }),
}));
afterEach(cleanup);
beforeEach(() => {
    native.results.clear();
    native.retry.mockReset();
});
const rows = [
    { id: 'a', title: 'A' },
    { id: 'b', title: 'B' },
];
const put = (args: unknown, raw: unknown, type = 'complete') =>
    native.results.set(JSON.stringify(args), [
        raw,
        { type, ...(type === 'error' ? { error: { message: 'Denied' }, retry: native.retry } : {}) },
    ]);

it('keeps completed empty results usable through background sync and errors', () => {
    put({}, []);
    const { result, rerender } = renderHook(() => useData(Task.query.all()));
    expect(result.current.status).toBe('success');
    put({}, [], 'unknown');
    rerender();
    expect(result.current.status).toBe('refreshing');
    put({}, [], 'error');
    rerender();
    expect(result.current.status).toBe('stale');
    expect(result.current.data).toEqual([]);
    act(() => result.current.retry());
    expect(native.retry).toHaveBeenCalledOnce();
});

it('keeps the committed sort live through rapid replacements, failure and retry', () => {
    put({}, rows);
    const { result, rerender } = renderHook(
        ({ direction }: { direction: 'asc' | 'desc' | undefined }) =>
            useData(Task.query.all().reorder(direction ? [['title', direction]] : undefined)),
        { initialProps: { direction: undefined as 'asc' | 'desc' | undefined } },
    );
    rerender({ direction: 'desc' });
    expect(result.current.status).toBe('refreshing');
    expect(result.current.data.map((row) => row.data.id)).toEqual(['a', 'b']);
    put({}, [{ id: 'a', title: 'Edited' }, rows[1]]);
    rerender({ direction: 'desc' });
    expect(result.current.data[0]?.data.title).toBe('Edited');
    rerender({ direction: 'asc' });
    put({ order: [['title', 'desc']] }, [rows[1], rows[0]]);
    rerender({ direction: 'asc' });
    expect(result.current.data[0]?.data.id).toBe('a');
    put({ order: [['title', 'asc']] }, [], 'error');
    rerender({ direction: 'asc' });
    expect(result.current.status).toBe('stale');
    act(() => result.current.retry());
    expect(native.retry).toHaveBeenCalledOnce();
    put({ order: [['title', 'asc']] }, [], 'complete');
    rerender({ direction: 'asc' });
    expect(result.current.status).toBe('success');
    expect(result.current.data).toEqual([]);
});

it('does not retain data across filters, includes, pagination scope, contracts or identity', () => {
    put({}, rows);
    const { result, rerender } = renderHook(({ selection }) => useData(selection), {
        initialProps: { selection: Task.query.all() },
    });
    rerender({ selection: Task.query.all().filter('title', 'other') });
    expect(result.current.status).toBe('loading');
    expect(result.current.data).toEqual([]);
    rerender({ selection: Task.query.all() });
    rerender({ selection: Task.query.all().with('comments') });
    expect(result.current.data).toEqual([]);
    rerender({ selection: Task.query.all() });
    rerender({ selection: Task.query.all().limit(2) });
    expect(result.current.data).toEqual([]);
    rerender({ selection: Task.query.all() });
    native.results.clear();
    native.zero = { context: { user_id: 'bob' } };
    rerender({ selection: Task.query.all().orderBy('title') });
    expect(result.current.status).toBe('loading');
    expect(result.current.data).toEqual([]);
});

it('reuses decoded rows and dates on an unrelated render with an inline selection', () => {
    put({}, [{ id: 'a', completed_at: 1700000000000 }]);
    const { result, rerender } = renderHook(() => useData(Task.query.all()));
    const data = result.current.data;
    const date = data[0]?.data.completed_at;
    rerender();
    expect(result.current.data).toBe(data);
    expect(result.current.data[0]?.data.completed_at).toBe(date);
});

it('distinguishes exact maximum from exhaustion and never offers impossible advancement', () => {
    const runtime = createZeroForLaravel(schema, {
        ...definitions,
        Task: { ...definitions.Task, maxLimit: 2, defaultLimit: 2 },
    } as const);
    put({ limit: 2 }, rows);
    const { result, rerender } = renderHook(
        ({ enabled }) => useData(runtime.models.Task.query.all().infinite(2), { enabled }),
        { initialProps: { enabled: true } },
    );
    expect(result.current.infinite).toMatchObject({
        capped: true,
        exhausted: false,
        hasMore: false,
        stopReason: 'maximum',
    });
    act(() => result.current.infinite?.loadMore());
    expect(result.current.infinite?.limit).toBe(2);
    put({ limit: 2 }, [rows[0]]);
    rerender({ enabled: true });
    expect(result.current.infinite).toMatchObject({ exhausted: true, stopReason: 'exhausted' });
    put({ limit: 2 }, [], 'error');
    rerender({ enabled: true });
    expect(result.current.infinite?.stopReason).toBeUndefined();
    rerender({ enabled: false });
    expect(result.current.status).toBe('idle');
    expect(result.current.infinite?.hasMore).toBeUndefined();
});

it('resets the growing window when contracts or their maximum change', () => {
    const make = (maxLimit: number) =>
        createZeroForLaravel(schema, {
            ...definitions,
            Task: { ...definitions.Task, defaultLimit: Math.min(2, maxLimit), maxLimit },
        } as const).models.Task;
    put({ limit: 3 }, [...rows, { id: 'c' }]);
    const { result, rerender } = renderHook(({ model }) => useData(model.query.all().infinite(2)), {
        initialProps: { model: make(6) },
    });
    act(() => result.current.infinite?.loadMore());
    expect(result.current.infinite?.limit).toBe(4);
    rerender({ model: make(1) });
    expect(result.current.infinite).toMatchObject({ limit: 1, capped: true, hasMore: false });
});

it('preserves an expanded live window across a sort-only transition', () => {
    const expanded = [...rows, { id: 'c' }, { id: 'd' }];
    put({ limit: 3 }, expanded.slice(0, 3));
    const { result, rerender } = renderHook(
        ({ sort }) =>
            useData(
                Task.query
                    .all()
                    .reorder(sort ? [['title', 'desc']] : undefined)
                    .infinite(2),
            ),
        { initialProps: { sort: false } },
    );
    act(() => result.current.infinite?.loadMore());
    put({ limit: 5 }, expanded);
    rerender({ sort: false });
    expect(result.current.infinite?.limit).toBe(4);
    expect(result.current.status).toBe('success');
    rerender({ sort: true });
    expect(result.current.infinite?.limit).toBe(4);
    expect(result.current.status).toBe('refreshing');
    expect(result.current.data.map((row) => row.data.id)).toEqual(['a', 'b', 'c', 'd']);
});

it('retains the native request when a consumer recreates an identical bounded selection', () => {
    const runtime = createZeroForLaravel(schema, {
        ...definitions,
        Task: {
            ...definitions.Task,
            filters: { ...definitions.Task.filters, ids: { kind: 'in', field: 'id', maxItems: 10 } },
        },
    } as const);
    const args = { filters: { ids: ['a', 'b'] }, limit: 2 };
    put(args, rows);
    const { result, rerender } = renderHook(() =>
        useData(runtime.models.Task.query.all().filter('ids', ['a', 'b']).limit(2)),
    );
    expect(result.current.status).toBe('success');
    for (let index = 0; index < 4; index++) rerender();
    expect(result.current.data.map((row) => row.data.id)).toEqual(['a', 'b']);
    expect(result.current.complete).toBe(true);
});

it('decodes controller query rows and resets live data when route arguments change', () => {
    const queries = defineQueries({
        page: defineQuery(({ args }: { args: { title: string } }) =>
            createBuilder(schema).tasks.where('title', args.title).related('list'),
        ),
        detail: defineQuery(({ args }: { args: { id: string } }) =>
            createBuilder(schema).tasks.where('id', args.id).one(),
        ),
    });
    const first = { id: 'a', title: 'A', completed_at: 1700000000123, list: { id: 'list-a', name: 'Work' } };
    put({ title: 'A' }, [first]);
    const { result, rerender } = renderHook(
        ({ title }) => useData(liveQuery(queries.page, { title }, definitions, 'Task', false)),
        { initialProps: { title: 'A' } },
    );
    expect(result.current.status).toBe('success');
    expect(result.current.data[0]?.completed_at?.toISOString()).toBe('2023-11-14T22:13:20.123Z');
    expect(result.current.data[0]?.list?.name).toBe('Work');
    const decoded = result.current.data[0];
    put({ title: 'A' }, [first, { id: 'b', title: 'A', completed_at: null }]);
    rerender({ title: 'A' });
    expect(result.current.data[0]).toBe(decoded);
    rerender({ title: 'B' });
    expect(result.current.status).toBe('loading');
    expect(result.current.data).toEqual([]);
    const detail = liveQuery(queries.detail, { id: 'a' }, definitions, 'Task', true);
    expect(detail.decode(undefined)).toBeUndefined();
    expect(detail.decode(first)?.title).toBe('A');
    expect(() => detail.decode([first])).toThrow('single replicated row');
});

it('grows a controller window with lookahead and resets it on changed arguments', () => {
    const queries = defineQueries({
        window: defineQuery(({ args }: { args: { title: string; __window?: number } }) =>
            createBuilder(schema)
                .tasks.where('title', args.title)
                .orderBy('id', 'asc')
                .limit(args['__window'] ?? 3),
        ),
    });
    put({ title: 'A', __window: 3 }, [...rows, { id: 'c', title: 'C' }]);
    const { result, rerender } = renderHook(
        ({ title }) => useData(liveQuery(queries.window, { title }, definitions, 'Task', false, 2)),
        { initialProps: { title: 'A' } },
    );
    expect(result.current.data.map((row) => row.id)).toEqual(['a', 'b']);
    expect(result.current.hasMore).toBe(true);
    const oldLoadMore = result.current.loadMore;
    act(() => {
        result.current.loadMore();
        result.current.loadMore();
    });
    expect(result.current.isLoadingMore).toBe(true);
    expect(result.current.infinite?.limit).toBe(4);
    put({ title: 'A', __window: 5 }, [...rows, { id: 'c', title: 'C' }]);
    rerender({ title: 'A' });
    expect(result.current.data.map((row) => row.id)).toEqual(['a', 'b', 'c']);
    expect(result.current.hasMore).toBe(false);
    rerender({ title: 'B' });
    act(oldLoadMore);
    expect(result.current.data).toEqual([]);
    expect(result.current.infinite?.limit).toBe(2);
});

it('navigates live cursor windows using raw values and ignores stale or repeated navigation', () => {
    const queries = defineQueries({
        pages: defineQuery(({ args }: { args: { title: string; __cursor?: { created_at: number; id: string } } }) => {
            const query = createBuilder(schema)
                .tasks.where('title', args.title)
                .orderBy('created_at', 'desc')
                .orderBy('id', 'asc')
                .limit(3);
            return args['__cursor'] ? query.start(args['__cursor']) : query;
        }),
    });
    const initial = [
        { id: 'a', title: 'A', created_at: 3000 },
        { id: 'b', title: 'B', created_at: 2000.25 },
        { id: 'c', title: 'C', created_at: 1000 },
    ];
    put({ title: 'A' }, initial);
    const { result, rerender } = renderHook(
        ({ title }) =>
            useData(liveQuery(queries.pages, { title }, definitions, 'Task', false, 2, ['created_at', 'id'])),
        { initialProps: { title: 'A' } },
    );
    expect(result.current.hasNextPage).toBe(true);
    expect(result.current.data.map((row) => row.id)).toEqual(['a', 'b']);
    const previousScopeNext = result.current.nextPage;
    act(() => {
        result.current.nextPage();
        result.current.nextPage();
    });
    expect(result.current.isChangingPage).toBe(true);
    expect(result.current.hasNextPage).toBeUndefined();
    put({ title: 'A', __cursor: { created_at: 2000.25, id: 'b' } }, [initial[2]]);
    rerender({ title: 'A' });
    expect(result.current.data.map((row) => row.id)).toEqual(['c']);
    expect(result.current.hasPreviousPage).toBe(true);
    expect(result.current.hasNextPage).toBe(false);
    put({ title: 'A' }, [{ ...initial[0], title: 'Updated' }, initial[1]]);
    act(() => result.current.previousPage());
    expect(result.current.data[0]?.title).toBe('Updated');
    expect(result.current.hasPreviousPage).toBe(false);
    rerender({ title: 'B' });
    act(previousScopeNext);
    expect(result.current.hasPreviousPage).toBe(false);
    expect(result.current.data).toEqual([]);
});

it('injects live Inertia props and stores complete binding metadata across partial visits', () => {
    const queries = defineQueries({
        tasks: defineQuery(({ args }: { args: { title: string } }) =>
            createBuilder(schema).tasks.where('title', args.title),
        ),
    });
    const adapter = createInertiaAdapter({
        tasks: (args: { title: string }) => liveQuery(queries.tasks, args, definitions, 'Task', false),
    });
    function Child() {
        const props = useLiveProps<{ heading: string; tasks?: Data<readonly { title: string }[]> }>();
        return (
            <p>
                {props.heading}: {props.tasks?.data.map((task) => task.title).join(',') ?? 'ordinary'}
            </p>
        );
    }
    const Page = Object.assign((_props: Record<string, unknown>) => <Child />, { layout: 'test-layout' });
    const page = {
        component: 'tasks/index',
        props: {
            heading: 'First',
            __zero: { version: 1, mode: 'replace', bindings: { tasks: { query: 'tasks', args: { title: 'A' } } } },
        },
    };
    put({ title: 'A' }, [{ id: 'a', title: 'live A' }]);
    try {
        const Wrapped = adapter.wrap(Page, page);
        expect(adapter.wrap(Page)).toBe(Wrapped);
        expect(Object.getOwnPropertyDescriptor(Wrapped, 'layout')?.value).toBe('test-layout');
        const view = render(<Wrapped {...page.props} />);
        expect(screen.getByText('First: live A')).toBeTruthy();
        expect(JSON.stringify(page)).not.toContain('live A');
        const partial = {
            component: page.component,
            props: { heading: 'Second', __zero: { version: 1, mode: 'patch', bindings: {} } },
        };
        document.dispatchEvent(new CustomEvent('inertia:beforeUpdate', { detail: { page: partial } }));
        document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page: partial } }));
        view.rerender(<Wrapped {...partial.props} />);
        expect(screen.getByText('Second: live A')).toBeTruthy();
        expect(partial.props['__zero']).toEqual(page.props['__zero']);
        const removed = {
            component: page.component,
            props: { heading: 'Third', __zero: { version: 1, mode: 'patch', bindings: { tasks: null } } },
        };
        document.dispatchEvent(new CustomEvent('inertia:beforeUpdate', { detail: { page: removed } }));
        document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page: removed } }));
        view.rerender(<Wrapped {...removed.props} />);
        expect(screen.getByText('Third: ordinary')).toBeTruthy();
        expect(removed.props['__zero'].bindings).toEqual({});
        const restored = JSON.parse(JSON.stringify(partial));
        document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page: restored } }));
        view.rerender(<Wrapped {...restored.props} />);
        expect(screen.getByText('Second: live A')).toBeTruthy();
    } finally {
        adapter.dispose();
    }
});
