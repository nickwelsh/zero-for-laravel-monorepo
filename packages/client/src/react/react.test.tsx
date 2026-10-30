// @vitest-environment jsdom
import { nestedRuntime } from '../../../../tests/interop/nested';
import { act, cleanup, fireEvent, render, renderHook, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { createZeroForLaravel } from '../runtime';
import { Task, schema, mutators, definitions } from '../../../../tests/interop/generated';
import { useData, createZeroProvider } from './index';
import { useFilter, useFilters } from './filters';
import { useMutation } from './mutation';

const native = vi.hoisted(() => ({
    raw: [] as unknown,
    details: { type: 'unknown' } as { type: string; error?: unknown },
    connection: { name: 'connected' },
    zero: { context: { user_id: 'alice' }, mutate: vi.fn() },
    query: vi.fn(),
    provider: vi.fn(),
}));
vi.mock('@rocicorp/zero/react', () => ({
    useQuery: (request: unknown) => {
        if (request) native.query(request);
        return [native.raw, native.details];
    },
    useZero: () => native.zero,
    useConnectionState: () => native.connection,
    ZeroProvider: (props: { children: unknown }) => {
        native.provider(props);
        return props.children;
    },
}));

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});
beforeEach(() => {
    native.raw = [];
    native.details = { type: 'unknown' };
    native.query.mockClear();
    native.zero.mutate.mockReset();
});

describe('useData', () => {
    it('distinguishes incomplete empty, partial, and complete empty results', () => {
        const { result, rerender } = renderHook(() => useData(Task.query().all()));
        expect(result.current.status).toBe('loading');
        native.raw = [{ id: 'a', title: 'cached' }];
        rerender();
        expect(result.current.status).toBe('refreshing');
        native.raw = [];
        native.details = { type: 'complete' };
        rerender();
        expect(result.current.status).toBe('success');
        expect(
            result.current.render({
                onIdle: () => 0,
                onLoading: () => 1,
                onRefreshing: () => 2,
                onFailure: () => 3,
                onStale: () => 4,
                onSuccess: () => 5,
            }),
        ).toBe(5);
    });

    it('reports failures, stale same-query data, and disabled queries', () => {
        const { result, rerender } = renderHook(({ enabled }) => useData(Task.query().all(), { enabled }), {
            initialProps: { enabled: true },
        });
        native.details = {
            type: 'error',
            error: { type: 'app', message: 'Denied' },
        };
        rerender({ enabled: true });
        expect(result.current.status).toBe('failure');
        native.raw = [{ id: 'a' }];
        rerender({ enabled: true });
        expect(result.current.status).toBe('stale');
        rerender({ enabled: false });
        expect(result.current.status).toBe('idle');
    });

    it('uses a lookahead row for hasMore and resets expanding limits when filters or identity change', () => {
        native.raw = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];
        native.details = { type: 'complete' };
        const { result, rerender } = renderHook(
            ({ title }) =>
                useData(Task.query().all().filter({ model: 'Task', name: 'title', value: title }).infinite(2)),
            { initialProps: { title: 'first' } },
        );
        expect(result.current.data).toHaveLength(2);
        expect(result.current.infinite?.hasMore).toBe(true);
        expect(native.query.mock.lastCall?.[0].args.limit).toBe(3);
        act(() => result.current.infinite?.loadMore());
        expect(native.query.mock.lastCall?.[0].args.limit).toBe(5);
        rerender({ title: 'second' });
        expect(native.query.mock.lastCall?.[0].args.limit).toBe(3);
        rerender({ title: 'first' });
        expect(native.query.mock.lastCall?.[0].args.limit).toBe(3);
        rerender({ title: 'second' });
        act(() => result.current.infinite?.loadMore());
        native.zero = { ...native.zero, context: { user_id: 'bob' } };
        rerender({ title: 'second' });
        expect(native.query.mock.lastCall?.[0].args.limit).toBe(3);
    });
});

describe('filter controls', () => {
    it('retains component identity and focus while debouncing query values', () => {
        vi.useFakeTimers();
        function useTitleFilter() {
            return useFilter(Task, 'title', { debounceMs: 100 });
        }
        let current: ReturnType<typeof useTitleFilter>;
        function Host() {
            current = useTitleFilter();
            const [, Control] = current;
            return <Control />;
        }
        render(<Host />);
        const input = screen.getByRole('textbox');
        input.focus();
        const control = current![1];
        fireEvent.change(input, { target: { value: 'Hello' } });
        expect(screen.getByRole('textbox')).toBe(input);
        expect(document.activeElement).toBe(input);
        expect(current![1]).toBe(control);
        expect(current![0].value).toBeUndefined();
        void act(() => vi.advanceTimersByTime(100));
        expect(current![0].value).toBe('Hello');
    });

    it('preserves false values and renders configured multiple controls', () => {
        function Host() {
            const [filters, Controls] = useFilters(Task, ['done', 'title']);
            return (
                <>
                    <Controls />
                    <output>{JSON.stringify(filters.map((filter) => filter.value))}</output>
                </>
            );
        }
        render(<Host />);
        fireEvent.change(screen.getByLabelText('done'), {
            target: { value: 'false' },
        });
        expect(screen.getByRole('status').textContent).toBe('[false,null]');
    });
});

describe('useMutation', () => {
    it('exposes native client/server outcomes and structured field errors', async () => {
        native.zero.mutate.mockReturnValue({
            client: Promise.resolve({ type: 'success' }),
            server: Promise.resolve({
                type: 'error',
                error: {
                    type: 'app',
                    message: 'Validation failed',
                    details: { fields: { title: ['Too short'] } },
                },
            }),
        });
        const { result } = renderHook(() => useMutation(Task.create()));
        let call: ReturnType<typeof result.current>;
        act(() => {
            call = result.current({ title: 'x' });
        });
        await act(async () => {
            await call!.server;
        });
        expect(result.current.state.status).toBe('failure');
        expect(result.current.state.fields).toEqual({ title: ['Too short'] });
        expect(call!.id).toMatch(/^[0-9a-f-]{36}$/);
    });
});

describe('generated provider binding', () => {
    it('binds schema and mutators and stabilizes equivalent context while isolating tenant storage', () => {
        const Provider = createZeroProvider(schema, mutators, { cacheURL: 'https://zero.example.test' });
        const { rerender } = render(
            <Provider userID="alice" context={{ user_id: 'alice', tenant_id: 'a' }}>
                <span>ready</span>
            </Provider>,
        );
        const first = native.provider.mock.lastCall![0];
        expect(first.schema).toBe(schema);
        expect(first.mutators).toBe(mutators);
        rerender(
            <Provider userID="alice" context={{ tenant_id: 'a', user_id: 'alice' }}>
                <span>ready</span>
            </Provider>,
        );
        expect(native.provider.mock.lastCall![0].context).toBe(first.context);
        rerender(
            <Provider userID="alice" context={{ user_id: 'alice', tenant_id: 'b' }}>
                <span>ready</span>
            </Provider>,
        );
        expect(native.provider.mock.lastCall![0].storageKey).not.toBe(first.storageKey);
    });
});

describe('bound mutation forms', () => {
    it('submits only edited fields, supports zero-argument delete, and resets on record changes', () => {
        native.zero.mutate.mockReturnValue({
            client: Promise.resolve({ type: 'success' }),
            server: new Promise(() => {}),
        });
        const rows = Task.query()
            .all()
            .decode([
                { id: 'a', title: 'Original', completed_at: null },
                { id: 'b', title: 'Other', completed_at: null },
            ]);
        const { result, rerender } = renderHook(({ index }) => useMutation(rows[index]!.update()), {
            initialProps: { index: 0 },
        });
        expect(result.current.defaultValues).toEqual({ title: 'Original', completed_at: null });
        act(() => {
            result.current.form.setValue('completed_at', new Date(1700000000000));
            result.current.form.setValue('title', 'Original');
        });
        act(() => {
            result.current.submit();
        });
        expect(native.zero.mutate.mock.lastCall![0].args).toMatchObject({
            id: 'a',
            data: { completed_at: 1700000000000 },
        });
        rerender({ index: 1 });
        expect(result.current.form.values).toEqual({ title: 'Other', completed_at: null });
        expect(result.current.state.status).toBe('idle');
        const deletion = renderHook(() => useMutation(rows[0]!.destroy()));
        act(() => {
            deletion.result.current.submit();
        });
        expect(native.zero.mutate.mock.lastCall![0].args).toMatchObject({ id: 'a', data: {} });
    });

    it('projects lazy relationship data through the parent query', () => {
        const task = Task.query()
            .all()
            .decode([{ id: 'a' }])[0]!;
        native.raw = { id: 'a', list: { id: 'l', name: 'Accessible' } };
        native.details = { type: 'complete' };
        const { result } = renderHook(() => useData(task.list));
        expect(result.current.data?.data.name).toBe('Accessible');
        expect(native.query.mock.lastCall![0].query.queryName).toBe('Task.one');
    });
});

describe('operator controls', () => {
    it('keeps the operator and typed input together when building a query', () => {
        function Host() {
            const [filter, Control] = useFilter(Task, 'title_operator');
            const selection = Task.query.all().filter(filter);
            return (
                <>
                    <Control label="Title" />
                    <output>{JSON.stringify(selection.args.filters)}</output>
                </>
            );
        }
        render(<Host />);
        fireEvent.change(screen.getByLabelText('Title operator'), { target: { value: '!=' } });
        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'hidden' } });
        expect(screen.getByRole('status').textContent).toBe('{"title_operator":{"operator":"!=","value":"hidden"}}');
    });
});

it('reports malformed cast data through the normal failure state and recovers after correction', () => {
    const runtime = createZeroForLaravel(schema, {
        ...definitions,
        Task: {
            ...definitions.Task,
            columns: { ...definitions.Task.columns, title: { ...definitions.Task.columns.title, cast: 'array' } },
        },
    } as const);
    native.raw = [{ id: 'a', title: '{broken' }];
    native.details = { type: 'complete' };
    const { result, rerender } = renderHook(() => useData(runtime.models.Task.query.all()));
    expect(result.current.status).toBe('failure');
    expect(result.current.data).toEqual([]);
    expect(result.current.error?.message).toContain('JSON');
    native.raw = [{ id: 'a', title: '{"nested":true}' }];
    rerender();
    expect(result.current.status).toBe('success');
    expect(result.current.data[0]?.data.title).toEqual({ nested: true });
});

it('edits nested form paths and submits one mutation with prefixed server errors', async () => {
    native.zero.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({
            type: 'error',
            error: { type: 'app', message: 'Invalid', details: { fields: { 'comments.0.body': ['Rejected'] } } },
        }),
    });
    const runtime = nestedRuntime();
    const { result, rerender } = renderHook(
        ({ nested }) =>
            useMutation(nested ? runtime.models.Task.create().with('comments') : runtime.models.Task.create()),
        { initialProps: { nested: true } },
    );
    act(() => {
        result.current.form.setValue('title', 'Root');
    });
    // The union of the two operations is deliberately widened in this lifecycle test.
    act(() => {
        (result.current.form.setValue as (field: string, value: unknown) => void)('comments.0.body', 'Comment');
    });
    expect(result.current.form.values).toEqual({ title: 'Root', comments: [{ body: 'Comment' }] });
    let call: ReturnType<typeof result.current>;
    act(() => {
        call = result.current.submit();
    });
    await act(async () => {
        await call!.server;
    });
    expect(native.zero.mutate).toHaveBeenCalledTimes(1);
    expect(native.zero.mutate.mock.lastCall![0].args.relations.comments.records[0].data).toEqual({ body: 'Comment' });
    expect(result.current.form.errors).toEqual({ 'comments.0.body': ['Rejected'] });
    rerender({ nested: false });
    expect(result.current.form.values).toEqual({});
});

it('hydrates nested update defaults and submits changed child fields with their IDs', () => {
    native.zero.mutate.mockReturnValue({ client: Promise.resolve({ type: 'success' }), server: new Promise(() => {}) });
    const runtime = nestedRuntime();
    const task = runtime.models.Task.query
        .all()
        .with('list')
        .with('comments')
        .decode([
            {
                id: 't',
                title: 'Task',
                list_id: 'l',
                list: { id: 'l', name: 'List' },
                comments: [{ id: 'c', task_id: 't', body: 'Old' }],
            },
        ])[0]!;
    const { result } = renderHook(() => useMutation(task.update().with('list').with('comments')));
    expect(result.current.defaultValues).toEqual({
        title: 'Task',
        list: { id: 'l', name: 'List' },
        comments: [{ id: 'c', body: 'Old' }],
    });
    act(() => {
        result.current.form.setValue('comments.0.body', 'New');
    });
    act(() => {
        result.current.submit();
    });
    expect(native.zero.mutate.mock.lastCall![0].args).toMatchObject({
        id: 't',
        data: {},
        relations: { comments: { operation: 'update', records: [{ id: 'c', data: { body: 'New' } }] } },
    });
    expect(native.zero.mutate.mock.lastCall![0].args.relations).not.toHaveProperty('list');
});
