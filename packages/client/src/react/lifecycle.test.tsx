// @vitest-environment jsdom
import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { Task, schema, definitions } from '../../../../tests/interop/generated';
import { createZeroForLaravel } from '../runtime';
import { useData } from './data';

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
