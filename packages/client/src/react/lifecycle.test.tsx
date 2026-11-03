// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { index } from '../../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { liveQuery, withQueryInputs } from '../live-query';
import { z } from 'zod';
import { router } from '@inertiajs/react';
import { useData, QueryAccessError, type Data, type QueryInputs } from './data';
import { createInertiaAdapter, useLiveProps } from './inertia';
import { AccessBoundary, AccessGeneration } from './access';
import { StrictMode, useContext } from 'react';

const native = vi.hoisted(() => ({
    results: new Map<
        string,
        readonly [
            unknown,
            { type: string; error?: { message: string; details?: { status: number } }; retry?: () => void },
        ]
    >(),
    zero: undefined as unknown as ReturnType<typeof mockZero>,
    requests: [] as { args: Record<string, unknown> }[],
    views: [] as NativeView[],
    retainedRevision: false,
    retry: vi.fn(),
}));
vi.mock('@rocicorp/zero/react', () => ({
    useQuery: (request: { args: Record<string, unknown> } | undefined, options: { enabled?: boolean }) => {
        if (request) native.requests.push(request);
        return !request || options.enabled === false
            ? [undefined, { type: 'unknown' }]
            : (native.results.get(JSON.stringify(request.args)) ?? [[], { type: 'unknown' }]);
    },
    useZero: () => native.zero,
    useConnectionState: () => native.zero.connection.state.current,
}));
interface NativeRequest {
    query: { queryName: string };
    args: { generation?: string; bindings?: { binding: string; query: string; args: object }[] };
}
class NativeView {
    listener?: (data: unknown, type: 'unknown' | 'cached' | 'complete' | 'error') => void;
    destroy = vi.fn();
    unsubscribe = vi.fn();
    constructor(
        readonly request: NativeRequest,
        readonly options: { ttl: number },
    ) {}
    addListener(listener: NonNullable<NativeView['listener']>) {
        this.listener = listener;
        listener([{ id: 'global', revision: 'one' }], native.retainedRevision ? 'complete' : 'unknown');
        return this.unsubscribe;
    }
    send(data: unknown, type: Parameters<NonNullable<NativeView['listener']>>[1] = 'complete') {
        // Deliberately deliver even after cleanup, to exercise stale callbacks.
        this.listener?.(data, type);
    }
    rows(state = 'allowed', status?: number) {
        return this.request.args.bindings!.map(({ binding }) => ({
            id: `${this.request.args.generation}:${binding}`,
            generation: this.request.args.generation,
            binding,
            state,
            status: status ?? null,
        }));
    }
}
function mockZero(user = 'alice') {
    const listeners = new Set<() => void>();
    const state = {
        current: { name: 'connected' },
        subscribe(listener: () => void) {
            listeners.add(listener);
            return () => listeners.delete(listener);
        },
    };
    return {
        context: { user_id: user },
        schema,
        connection: { state },
        connect(name: string) {
            state.current = { name };
            listeners.forEach((listener) => listener());
        },
        materialize(request: NativeRequest, options: { ttl: number }) {
            const view = new NativeView(request, options);
            native.views.push(view);
            return view;
        },
    };
}
const revisions = () => native.views.filter((view) => view.request.query.queryName === '__zero_access_revision');
const checks = () => native.views.filter((view) => view.request.query.queryName === '__zero_access_status');
async function revision(value = 'one') {
    await act(async () =>
        revisions()
            .at(-1)!
            .send([{ id: 'global', revision: value }]),
    );
}
async function answer(state = 'allowed', status?: number, view = checks().at(-1)!) {
    await act(async () => view.send(view.rows(state, status)));
}
afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});
beforeEach(() => {
    native.zero = mockZero();
    native.results.clear();
    native.views.length = 0;
    native.requests.length = 0;
    native.retainedRevision = false;
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

it.each([403, 404] as const)('discards previously decoded rows on an authoritative %s query error', (status) => {
    put({ title: '' }, rows);
    const { result, rerender } = renderHook(() => useData(index.tasks.live({ title: '' })));
    expect(result.current.data).toHaveLength(2);
    native.results.set(JSON.stringify({ title: '' }), [
        rows,
        { type: 'error', error: { message: 'Denied', details: { status } }, retry: native.retry },
    ]);
    rerender();
    expect(result.current.status).toBe('failure');
    expect(result.current.data).toEqual([]);
    expect(result.current.error).toBeInstanceOf(QueryAccessError);
    expect((result.current.error as QueryAccessError).status).toBe(status);
});

it.each([403, 404] as const)(
    'uses native status data, unmounts before %s callbacks, and restores a new business identity',
    async (status) => {
        const fetch = vi.fn();
        const eventSource = vi.fn();
        vi.stubGlobal('fetch', fetch);
        vi.stubGlobal('EventSource', eventSource);
        const revoked = vi.fn(() => expect(screen.queryByText('Private contents')).toBeNull());
        const adapter = createInertiaAdapter(
            { tasks: (args: { title: string }) => index.tasks.live(args) },
            { access: { onRevoked: revoked } },
        );
        const page = {
            component: 'tasks',
            props: {
                __zero: {
                    version: 1,
                    mode: 'replace',
                    bindings: {
                        tasks: { query: 'tasks', args: { title: '' }, access: { status } },
                    },
                },
            },
        };
        const Wrapped = adapter.wrap(() => <p>Private contents</p>, page);
        put({ title: '' }, rows); // Persisted rows under the old identity are not sufficient.
        const view = render(<Wrapped {...page.props} />);
        try {
            expect(native.requests).toHaveLength(0);
            await revision();
            await answer('denied', status);
            expect(screen.getByRole('alert').getAttribute('data-zero-access')).toBe(String(status));
            expect(revoked).not.toHaveBeenCalled();
            await answer();
            expect(screen.queryByText('Private contents')).toBeNull();
            const first = native.requests.at(-1)!.args;
            expect(first['__generation']).toMatch(/^[\da-f-]{36}$/);
            put(first, rows, 'cached');
            view.rerender(<Wrapped {...page.props} />);
            expect(screen.queryByText('Private contents')).toBeNull();
            put(first, rows);
            view.rerender(<Wrapped {...page.props} />);
            expect(screen.getByText('Private contents')).toBeTruthy();
            put(first, rows, 'error');
            view.rerender(<Wrapped {...page.props} />);
            expect(screen.queryByText('Private contents')).toBeNull();
            expect(revoked).not.toHaveBeenCalled();
            await answer('denied', status);
            expect(revoked).toHaveBeenCalledOnce();
            await answer('denied', status);
            expect(revoked).toHaveBeenCalledOnce();
            await answer();
            const second = native.requests.at(-1)!.args;
            expect(second['__generation']).not.toBe(first['__generation']);
            expect(screen.queryByText('Private contents')).toBeNull();
            put(second, []); // A completed empty collection is not a denial.
            view.rerender(<Wrapped {...page.props} />);
            expect(screen.getByText('Private contents')).toBeTruthy();
            expect(fetch).not.toHaveBeenCalled();
            expect(eventSource).not.toHaveBeenCalled();
            await act(async () => view.unmount());
            expect(native.views.every((resource) => resource.destroy.mock.calls.length === 1)).toBe(true);
            expect(native.views.every((resource) => resource.unsubscribe.mock.calls.length === 1)).toBe(true);
            await answer();
            expect(screen.queryByText('Private contents')).toBeNull();
        } finally {
            view.unmount();
            adapter.dispose();
        }
    },
);

const protectedBinding = { query: 'tasks', args: { title: '' }, access: { status: 403 as const } };
function BusinessIdentity() {
    return <p>Private {useContext(AccessGeneration)}</p>;
}

it('rejects retained revision completion, cached-only status, malformed/missing/duplicate rows and transport errors', async () => {
    native.retainedRevision = true;
    const revoked = vi.fn();
    render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{ onRevoked: revoked }}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    await act(async () => {});
    expect(checks()).toHaveLength(0);
    await act(async () => revisions()[0]!.send([{ id: 'global', revision: 'one' }], 'cached'));
    expect(checks()).toHaveLength(0);
    for (const data of [
        [],
        [{ id: 'other', revision: 'one' }],
        [{ id: 'global', revision: null }],
        [{ id: 'global', revision: '' }],
        [
            { id: 'global', revision: 'one' },
            { id: 'global', revision: 'two' },
        ],
    ]) {
        act(() => revisions()[0]!.send(data));
        expect(checks()).toHaveLength(0);
    }
    await revision();
    const check = checks()[0]!;
    const row = check.rows()[0]!;
    for (const type of ['cached', 'unknown', 'error'] as const) {
        act(() => check.send([row], type));
        expect(screen.queryByText(/^Private/)).toBeNull();
        expect(screen.queryByRole('alert')).toBeNull();
    }
    for (const data of [
        [],
        null,
        [null],
        [{ ...row, generation: 'obsolete' }],
        [{ ...row, binding: 'other' }],
        [{ ...row, id: '' }],
        [{ ...row, state: 'unknown' }],
        [{ ...row, status: '403' }],
        [{ ...row, state: 'denied', status: 500 }],
        [row, row],
    ]) {
        act(() => check.send(data));
        expect(screen.queryByText(/^Private/)).toBeNull();
    }
    expect(checks()).toHaveLength(1); // Empty complete must not cause a render/recheck loop.
    await answer();
    expect(screen.getByText(/^Private/)).toBeTruthy();
    act(() => check.send([])); // Removal of a previously confirmed row fails closed.
    expect(screen.queryByText(/^Private/)).toBeNull();
    expect(revoked).not.toHaveBeenCalled();
});

it('renews with fresh UUIDs without changing business identity, and expires from request start, not response time', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    const view = render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    await revision();
    await act(() => vi.advanceTimersByTime(10_000));
    await answer();
    const identity = screen.getByText(/^Private/).textContent;
    await act(() => vi.advanceTimersByTime(5_000));
    expect(checks()).toHaveLength(2);
    expect(checks()[1]!.request.args.generation).not.toBe(checks()[0]!.request.args.generation);
    expect(screen.getByText(/^Private/).textContent).toBe(identity);
    act(() => checks()[1]!.send([], 'error'));
    expect(screen.getByText(/^Private/).textContent).toBe(identity);
    await answer();
    expect(screen.getByText(/^Private/).textContent).toBe(identity);
    vi.setSystemTime(Date.now() - 3_600_000); // Wall-clock rollback must not extend the lease.
    await act(() => vi.advanceTimersByTime(15_000)); // Third request never completes.
    await act(() => vi.advanceTimersByTime(4_999));
    expect(screen.getByText(/^Private/)).toBeTruthy();
    await act(() => vi.advanceTimersByTime(1)); // Second request began at 15s: lease ends at 35s.
    expect(screen.queryByText(/^Private/)).toBeNull();
    await answer('allowed', undefined, checks()[1]); // Expired/superseded generation cannot extend authority.
    expect(screen.queryByText(/^Private/)).toBeNull();
    await act(async () => view.unmount());
    expect(vi.getTimerCount()).toBe(0);
});

it('rejects an expired current-generation response even when renewal and expiry timers have been suspended', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    const view = render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    await revision();
    const clock = vi.spyOn(performance, 'now').mockReturnValue(20_001);
    try {
        // Simulate a suspended event loop delivering the network callback before
        // its overdue timers. The UUID is still current, but its authority is not.
        await answer();
        expect(checks()).toHaveLength(1);
        expect(screen.queryByText(/^Private/)).toBeNull();
        await act(async () => view.unmount());
        expect(vi.getTimerCount()).toBe(0);
    } finally {
        view.unmount();
        clock.mockRestore();
    }
});

it('backs off empty checks and retains denied subscriptions for eventual regrant', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    await revision();
    act(() => checks()[0]!.send([]));
    await act(() => vi.advanceTimersByTime(999));
    expect(checks()).toHaveLength(1);
    await act(() => vi.advanceTimersByTime(1));
    expect(checks()).toHaveLength(2);
    act(() => checks()[1]!.send([]));
    await act(() => vi.advanceTimersByTime(1999));
    expect(checks()).toHaveLength(2);
    await act(() => vi.advanceTimersByTime(1));
    await answer('denied', 404);
    expect(screen.getByRole('alert').textContent).toBe('Not found');
    await act(() => vi.advanceTimersByTime(15_000));
    await answer();
    expect(screen.getByText(/^Private/)).toBeTruthy();
});

it('retries revision materialization failures at a bounded rate and cancels pending retry timers', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    const materialize = vi.spyOn(native.zero, 'materialize').mockImplementationOnce(() => {
        throw new Error('transport');
    });
    const view = render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    expect(screen.queryByText(/^Private/)).toBeNull();
    expect(materialize).toHaveBeenCalledOnce();
    await act(() => vi.advanceTimersByTime(999));
    expect(materialize).toHaveBeenCalledOnce();
    await act(() => vi.advanceTimersByTime(1));
    expect(revisions()).toHaveLength(1);
    act(() => revisions()[0]!.send([], 'error'));
    expect(screen.queryByRole('alert')).toBeNull();
    await act(async () => view.unmount());
    expect(vi.getTimerCount()).toBe(0);
    materialize.mockRestore();
});

it('observes synchronous disconnect/reconnect epochs and requires a fresh revision and status even when the revision is unchanged', async () => {
    render(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    await revision();
    await answer();
    const identity = screen.getByText(/^Private/).textContent;
    const oldRevision = revisions()[0]!;
    const oldCheck = checks()[0]!;
    native.retainedRevision = true;
    act(() => {
        native.zero.connect('disconnected');
        native.zero.connect('connected');
    });
    expect(screen.queryByText(/^Private/)).toBeNull();
    expect(revisions()).toHaveLength(2);
    expect(revisions()[1]!.request.args.generation).toMatch(/^[a-f0-9-]{36}$/);
    expect(revisions()[1]!.request.args.generation).not.toBe(oldRevision.request.args.generation);
    expect(oldRevision.destroy).toHaveBeenCalledOnce();
    expect(oldCheck.destroy).toHaveBeenCalledOnce();
    act(() => oldRevision.send([{ id: 'global', revision: 'one' }]));
    await answer('allowed', undefined, oldCheck);
    expect(checks()).toHaveLength(1);
    expect(screen.queryByText(/^Private/)).toBeNull();
    await revision();
    expect(checks()).toHaveLength(2);
    await answer();
    expect(screen.getByText(/^Private/).textContent).not.toBe(identity);
    expect(native.views.every((view) => view.options.ttl === 0)).toBe(true);
});

it('coalesces revisions, invalidates visibility restoration, and never shares approval across contexts or Zero instances', async () => {
    const boundary = (
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>
    );
    const view = render(boundary);
    await revision();
    await answer();
    const old = checks()[0]!;
    await act(async () => {
        revisions()[0]!.send([{ id: 'global', revision: 'two' }]);
        revisions()[0]!.send([{ id: 'global', revision: 'three' }]);
    });
    expect(screen.queryByText(/^Private/)).toBeNull();
    expect(checks()).toHaveLength(2);
    await answer('allowed', undefined, old);
    expect(screen.queryByText(/^Private/)).toBeNull();
    await answer();
    await act(async () => document.dispatchEvent(new Event('visibilitychange')));
    expect(screen.queryByText(/^Private/)).toBeNull();
    expect(checks()).toHaveLength(3);
    await answer();
    native.zero.context = { user_id: 'bob' };
    view.rerender(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    expect(screen.queryByText(/^Private/)).toBeNull();
    await revision();
    await answer();
    native.zero = mockZero('bob');
    view.rerender(
        <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
            <BusinessIdentity />
        </AccessBoundary>,
    );
    expect(screen.queryByText(/^Private/)).toBeNull();
    await revision();
    await answer();
    expect(screen.getByText(/^Private/)).toBeTruthy();
});

it('shares identical bindings and splits native batches at 32 without invalidating sibling approvals', async () => {
    const boundaries = Array.from({ length: 65 }, (_, position) => (
        <AccessBoundary
            key={position}
            binding={{ ...protectedBinding, args: { title: String(position) } }}
            prop={String(position)}
            options={{}}
        >
            <p>Private {position}</p>
        </AccessBoundary>
    ));
    render(
        <>
            {boundaries}
            <AccessBoundary binding={{ ...protectedBinding, args: { title: '0' } }} prop="duplicate" options={{}}>
                <p>Duplicate</p>
            </AccessBoundary>
        </>,
    );
    await revision();
    expect(revisions()).toHaveLength(1);
    expect(checks().map((view) => view.request.args.bindings!.length)).toEqual([32, 32, 1]);
    for (const check of checks()) await answer('allowed', undefined, check);
    expect(screen.getAllByText(/^Private/)).toHaveLength(65);
    expect(screen.getByText('Duplicate')).toBeTruthy();
});

it('keeps one revision view through StrictMode subscription handoffs and cleans up all resources', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    const view = render(
        <StrictMode>
            <AccessBoundary binding={protectedBinding} prop="tasks" options={{}}>
                <BusinessIdentity />
            </AccessBoundary>
        </StrictMode>,
    );
    expect(revisions()).toHaveLength(1);
    expect(screen.queryByText(/^Private/)).toBeNull();
    await revision();
    await answer();
    expect(screen.getByText(/^Private/)).toBeTruthy();
    await act(async () => view.unmount());
    expect(native.views.every((resource) => resource.destroy.mock.calls.length === 1)).toBe(true);
    expect(vi.getTimerCount()).toBe(0);
});

it('authorizes nested Inertia bindings independently without unmount loops or renewing business identities', async () => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date', 'performance'] });
    const adapter = createInertiaAdapter({ tasks: (args: { title: string }) => index.tasks.live(args) });
    const page = {
        component: 'tasks',
        props: {
            __zero: {
                version: 1,
                mode: 'replace',
                bindings: {
                    first: { ...protectedBinding, args: { title: 'first' } },
                    second: { ...protectedBinding, args: { title: 'second' } },
                },
            },
        },
    };
    const Wrapped = adapter.wrap(() => <p>Nested private page</p>, page);
    const view = render(<Wrapped {...page.props} />);
    try {
        await revision();
        await answer();
        const first = native.requests.at(-1)!.args;
        put(first, []);
        view.rerender(<Wrapped {...page.props} />);
        await act(async () => {});
        expect(checks()).toHaveLength(2);
        expect(checks()[1]!.request.args.bindings!.map((binding) => binding.args)).toEqual([{ title: 'second' }]);
        expect(native.requests.at(-1)!.args).toEqual(first);
        await answer();
        const second = native.requests.at(-1)!.args;
        expect(second.title).toBe('second');
        put(second, []);
        view.rerender(<Wrapped {...page.props} />);
        expect(screen.getByText('Nested private page')).toBeTruthy();
        await act(() => vi.advanceTimersByTime(15_000));
        expect(checks()).toHaveLength(3);
        expect(checks()[2]!.request.args.bindings).toHaveLength(2);
        await answer();
        expect(screen.getByText('Nested private page')).toBeTruthy();
        expect(
            native.requests
                .filter((request) => request.args.title === 'first')
                .every((request) => request.args['__generation'] === first['__generation']),
        ).toBe(true);
        expect(
            native.requests
                .filter((request) => request.args.title === 'second')
                .every((request) => request.args['__generation'] === second['__generation']),
        ).toBe(true);
    } finally {
        view.unmount();
        adapter.dispose();
    }
});

it('checks the actual edited Inertia inputs before mounting the corresponding business query', async () => {
    const parse = z.strictObject({ input: z.strictObject({ title: z.string() }) });
    const queries = defineQueries({
        tasks: defineQuery(({ args }: { args: z.infer<typeof parse> }) =>
            createBuilder(schema).tasks.where('title', args.input.title),
        ),
    });
    const factory = (args: z.infer<typeof parse>) =>
        withQueryInputs(liveQuery(queries.tasks, args, definitions, 'Task', false), args, 'input', {}, (value) =>
            parse.parse(value),
        );
    const Page = ({
        tasks,
    }: {
        tasks: Data<readonly { title: string }[]> & { inputs: QueryInputs<{ title: string }, object> };
    }) => (
        <>
            <p>{tasks.data.map((row) => row.title).join(',')}</p>
            <button onClick={() => tasks.inputs.set({ title: 'Changed' })}>Edit</button>
        </>
    );
    const adapter = createInertiaAdapter({ tasks: factory });
    const page = {
        component: 'tasks',
        props: {
            __zero: {
                version: 1,
                mode: 'replace',
                bindings: {
                    tasks: { query: 'tasks', args: { input: { title: 'Seed' } }, access: { status: 403 } },
                },
            },
        },
    };
    const Wrapped = adapter.wrap(Page, page);
    const props = page.props as unknown as Parameters<typeof Page>[0];
    const view = render(<Wrapped {...props} />);
    try {
        await revision();
        await answer();
        put(native.requests.at(-1)!.args, [{ id: 'a', title: 'Seed' }]);
        view.rerender(<Wrapped {...props} />);
        expect(screen.getByText('Seed')).toBeTruthy();
        await act(async () => fireEvent.click(screen.getByRole('button', { name: 'Edit' })));
        expect(screen.queryByText('Seed')).toBeNull();
        expect(checks().at(-1)!.request.args.bindings![0]!.args).toEqual({ input: { title: 'Changed' } });
        expect(native.requests.at(-1)!.args.input).toEqual({ title: 'Seed' });
        await answer('allowed', undefined, checks()[0]);
        expect(screen.queryByRole('button')).toBeNull();
        await answer();
        const request = native.requests.at(-1)!.args;
        expect(request.input).toEqual({ title: 'Changed' });
        expect(screen.queryByRole('button')).toBeNull();
        put(request, [{ id: 'b', title: 'Changed' }]);
        view.rerender(<Wrapped {...props} />);
        expect(screen.getByText('Changed')).toBeTruthy();
    } finally {
        view.unmount();
        adapter.dispose();
    }
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
    native.zero = mockZero('bob');
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
