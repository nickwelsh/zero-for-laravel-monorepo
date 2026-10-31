// @vitest-environment jsdom
import { act, cleanup, render, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { index, choices } from '../../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { createControllerMutationBindings } from '../controller-mutation.fixture';
import { liveQuery } from '../index';
import { useData, createZeroProvider } from './index';

const native = vi.hoisted(() => ({
    raw: [] as unknown,
    details: { type: 'unknown' } as { type: string; error?: unknown },
    zero: { context: { user_id: 'alice' } },
    query: vi.fn(),
    provider: vi.fn(),
}));
vi.mock('@rocicorp/zero/react', () => ({
    useQuery: (request: unknown) => {
        if (request) native.query(request);
        return [native.raw, native.details];
    },
    useZero: () => native.zero,
    useConnectionState: () => ({ name: 'connected' }),
    ZeroProvider: (props: { children: unknown }) => {
        native.provider(props);
        return props.children;
    },
}));
afterEach(cleanup);
beforeEach(() => {
    native.raw = [];
    native.details = { type: 'unknown' };
    native.query.mockClear();
});

it('distinguishes incomplete empty, partial, complete, failed, stale, and disabled controller results', () => {
    const { result, rerender } = renderHook(({ enabled }) => useData(index.tasks.live({ title: '' }), { enabled }), {
        initialProps: { enabled: true },
    });
    expect(result.current.status).toBe('loading');
    native.details = { type: 'error', error: { type: 'app', message: 'Denied' } };
    rerender({ enabled: true });
    expect(result.current.status).toBe('failure');
    native.raw = [{ id: 'a', title: 'cached' }];
    rerender({ enabled: true });
    expect(result.current.status).toBe('stale');
    native.details = { type: 'unknown' };
    rerender({ enabled: true });
    expect(result.current.status).toBe('refreshing');
    native.raw = [];
    native.details = { type: 'complete' };
    rerender({ enabled: true });
    expect(result.current.status).toBe('success');
    expect(
        result.current.render({
            onIdle: () => 'idle',
            onLoading: () => 'loading',
            onRefreshing: () => 'refreshing',
            onFailure: () => 'failure',
            onStale: () => 'stale',
            onSuccess: () => 'success',
        }),
    ).toBe('success');
    rerender({ enabled: false });
    expect(result.current.status).toBe('idle');
});

it('uses controller lookahead and resets expanded windows when input or identity changes', () => {
    native.raw = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];
    native.details = { type: 'complete' };
    const { result, rerender } = renderHook(
        ({ status }: { status: 'active' | 'completed' }) => useData(choices.tasks.live({ input: { status } })),
        { initialProps: { status: 'active' } },
    );
    expect(result.current.data).toHaveLength(2);
    expect(result.current.hasMore).toBe(true);
    expect(native.query.mock.lastCall?.[0].args).toEqual({ input: { status: 'active' }, __window: 3 });
    act(() => result.current.loadMore());
    expect(native.query.mock.lastCall?.[0].args).toEqual({ input: { status: 'active' }, __window: 5 });
    rerender({ status: 'completed' });
    expect(native.query.mock.lastCall?.[0].args).toEqual({ input: { status: 'completed' }, __window: 3 });
    act(() => result.current.loadMore());
    native.zero = { context: { user_id: 'bob' } };
    rerender({ status: 'completed' });
    expect(native.query.mock.lastCall?.[0].args).toEqual({ input: { status: 'completed' }, __window: 3 });
});

it('binds generated controller mutators and stabilizes equivalent context while isolating tenant storage', () => {
    const { mutators } = createControllerMutationBindings(schema, definitions, {
        'ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected': async () => ({ pending: true }),
    });
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

it('reports malformed controller cast data and recovers after correction', () => {
    const queries = defineQueries({
        tasks: defineQuery(({ args: _args }: { args: Record<string, never> }) => createBuilder(schema).tasks),
    });
    const models = {
        ...definitions,
        Task: {
            ...definitions.Task,
            columns: { ...definitions.Task.columns, title: { ...definitions.Task.columns.title, cast: 'array' } },
        },
    } as const;
    native.raw = [{ id: 'a', title: '{broken' }];
    native.details = { type: 'complete' };
    const { result, rerender } = renderHook(() => useData(liveQuery(queries.tasks, {}, models, 'Task', false)));
    expect(result.current.status).toBe('failure');
    expect(result.current.data).toEqual([]);
    expect(result.current.error?.message).toContain('JSON');
    native.raw = [{ id: 'a', title: '{"nested":true}' }];
    rerender();
    expect(result.current.status).toBe('success');
    expect(result.current.data[0]?.title).toEqual({ nested: true });
});
