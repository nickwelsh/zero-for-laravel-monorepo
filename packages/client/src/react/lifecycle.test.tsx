// @vitest-environment jsdom
import { act, cleanup, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { index } from '../../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { liveQuery, withQueryInputs } from '../live-query';
import { z } from 'zod';
import { router } from '@inertiajs/react';
import { useData, type Data, type QueryInputs } from './data';
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
    put({ title: '' }, []);
    const { result, rerender } = renderHook(() => useData(index.tasks.live({ title: '' })));
    expect(result.current.status).toBe('success');
    put({ title: '' }, [], 'unknown');
    rerender();
    expect(result.current.status).toBe('refreshing');
    put({ title: '' }, [], 'error');
    rerender();
    expect(result.current.status).toBe('stale');
    expect(result.current.data).toEqual([]);
    act(() => result.current.retry());
    expect(native.retry).toHaveBeenCalledOnce();
});

it('does not retain data across controller arguments or authorization identities', () => {
    put({ title: '' }, rows);
    const { result, rerender } = renderHook(({ selection }) => useData(selection), {
        initialProps: { selection: index.tasks.live({ title: '' }) },
    });
    rerender({ selection: index.tasks.live({ title: 'other' }) });
    expect(result.current.status).toBe('loading');
    expect(result.current.data).toEqual([]);
    rerender({ selection: index.tasks.live({ title: '' }) });
    native.results.clear();
    native.zero = { context: { user_id: 'bob' } };
    rerender({ selection: index.tasks.live({ title: '' }) });
    expect(result.current.status).toBe('loading');
    expect(result.current.data).toEqual([]);
});

it('reuses decoded rows and dates on an unrelated render with an inline selection', () => {
    put({ title: '' }, [{ id: 'a', completed_at: 1700000000000 }]);
    const { result, rerender } = renderHook(() => useData(index.tasks.live({ title: '' })));
    const data = result.current.data;
    const date = data[0]?.completed_at;
    rerender();
    expect(result.current.data).toBe(data);
    expect(result.current.data[0]?.completed_at).toBe(date);
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

it('edits validated query inputs without changing route identity, and restores initial inputs', () => {
    const input = z.strictObject({
        list: z.string(),
        input: z.strictObject({
            status: z.enum(['active', 'completed']).nullable().optional(),
            title: z.string().optional(),
        }),
    });
    const queries = defineQueries({
        tasks: defineQuery(({ args }: { args: z.infer<typeof input> }) =>
            createBuilder(schema).tasks.where('title', args.input.title ?? ''),
        ),
    });
    const factory = (args: z.infer<typeof input>) =>
        withQueryInputs(
            liveQuery(queries.tasks, args, definitions, 'Task', false),
            args,
            'input',
            { status: ['active', 'completed'] as const },
            (value) => input.parse(value),
        );
    let live: Data<unknown> & {
        inputs: QueryInputs<z.infer<typeof input>['input'], { status: readonly ['active', 'completed'] }>;
    };
    const Page = (props: { tasks: typeof live }) => {
        live = props.tasks;
        return <p>{JSON.stringify(props.tasks.inputs.values)}</p>;
    };
    const args = { list: 'fixed', input: { status: 'active' as const, title: 'Seed' } };
    const page = {
        component: 'tasks',
        props: { __zero: { version: 1, mode: 'replace', bindings: { tasks: { query: 'tasks', args } } } },
    };
    const replace = vi.spyOn(router, 'replace').mockImplementation(() => {});
    const adapter = createInertiaAdapter({ tasks: factory }, { url: { tasks: { tasks: { status: 'status' } } } });
    put(args, [{ id: 'a', title: 'Seed' }]);
    put({ list: 'fixed', input: { status: 'completed', title: 'Changed' } }, [{ id: 'b', title: 'Changed' }]);
    try {
        const Wrapped = adapter.wrap(Page, page);
        const view = render(<Wrapped {...(page.props as unknown as { tasks: typeof live })} />);
        expect(live!.inputs.options.status).toEqual(['active', 'completed']);
        act(() => {
            live.inputs.set({ status: 'completed' });
            live.inputs.set((current) => ({ title: current.status === 'completed' ? 'Changed' : 'Wrong' }));
        });
        expect(live!.data).toEqual([{ id: 'b', title: 'Changed' }]);
        expect(replace.mock.lastCall?.[0].url).toBe('/?status=completed');
        for (const [visit] of replace.mock.calls.slice(0, 2)) {
            if (typeof visit.props !== 'function') throw new Error('Expected serializable history update.');
            const acknowledgement = { ...page, url: visit.url, props: visit.props(page.props, {}) };
            document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: { page: acknowledgement } }));
            view.rerender(<Wrapped {...(acknowledgement.props as unknown as { tasks: typeof live })} />);
            expect(live!.inputs.values).toEqual({ status: 'completed', title: 'Changed' });
        }
        expect(() => act(() => live.inputs.set(JSON.parse('{"status":"invalid"}')))).toThrow();
        expect(() => act(() => live.inputs.set(JSON.parse('{"list":"other"}')))).toThrow();
        expect(live!.inputs.values).toEqual({ status: 'completed', title: 'Changed' });
        act(() => live.inputs.set({ status: null }));
        expect(live!.inputs.values.status).toBeNull();
        act(() => live.inputs.set({ status: undefined }));
        expect(live!.inputs.values).not.toHaveProperty('status');
        act(() => live.inputs.reset());
        expect(live!.inputs.values).toEqual(args.input);
        const partial = { component: 'tasks', props: { __zero: { version: 1, mode: 'patch', bindings: {} } } };
        act(() => live.inputs.set({ title: 'Local' }));
        document.dispatchEvent(new CustomEvent('inertia:beforeUpdate', { detail: { page: partial } }));
        view.rerender(<Wrapped {...(partial.props as unknown as { tasks: typeof live })} />);
        expect(live!.inputs.values.title).toBe('Local');
        const next = {
            ...page.props,
            __zero: {
                ...page.props['__zero'],
                bindings: { tasks: { query: 'tasks', args: { list: 'different', input: {} } } },
            },
        };
        view.rerender(<Wrapped {...(next as unknown as { tasks: typeof live })} />);
        expect(live!.inputs.values).toEqual({});
        const detached = live!.inputs.set;
        view.unmount();
        replace.mockClear();
        act(() => detached({ status: 'active' }));
        expect(replace).not.toHaveBeenCalled();
    } finally {
        adapter.dispose();
        replace.mockRestore();
    }
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
