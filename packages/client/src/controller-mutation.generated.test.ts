// @vitest-environment jsdom
import { Zero, createBuilder } from '@rocicorp/zero';
import { ZeroContext, useQuery } from '@rocicorp/zero/react';
import { createElement, type ReactNode } from 'react';
import { renderHook, waitFor } from '@testing-library/react';
import { expect, expectTypeOf, it } from 'vite-plus/test';
import { schema, definitions } from '../../../tests/interop/generated';
import { createControllerMutationBindings } from './controller-mutation.fixture';
import type { ClientContext } from './contract';
import { createZeroBindings, useZero } from '../../../tests/interop/controller-react.generated';
import {
    projected as projectedAction,
    store as storeAction,
} from '../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';
import {
    index,
    show,
    dated,
    choices,
} from '../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import type {
    InferQueryInput,
    InferQueryResult,
    InferLiveQueryResult,
    InferMutationInput,
    InferMutationResult,
} from './index';

const prefix = 'ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@' as const;
const projections = {
    [projectedAction.action]: async () => ({ pending: true }),
};

it('executes the PHP-generated typed bindings without application-authored codecs', async () => {
    const generated = createZeroBindings(projections);
    expect(generated.ZeroProvider).toBeTypeOf('function');
    const store = generated.actions[storeAction.action];
    const complete = generated.actions[`${prefix}complete`];
    const noResult = generated.actions[`${prefix}noResult`];
    const authoritative = generated.actions[`${prefix}authoritative`];
    expectTypeOf<Awaited<ReturnType<typeof store.execute>>>().toEqualTypeOf<{ id: string }>();
    expectTypeOf<ReturnType<typeof complete.clientResultSchema.parse>>().toEqualTypeOf<
        { before: boolean; after: boolean } | undefined
    >();
    expectTypeOf<Awaited<ReturnType<typeof noResult.execute>>>().toEqualTypeOf<undefined>();
    expectTypeOf<Awaited<ReturnType<typeof authoritative.execute>>>().toEqualTypeOf<null>();
    expect(() => store.serverResultSchema.parse({ id: 1 })).toThrow();
    expect(() => authoritative.serverResultSchema.parse(undefined)).toThrow();
    expect(() => noResult.serverResultSchema.parse(null)).toThrow();
    const structured = generated.actions[`${prefix}structured`];
    expectTypeOf<Awaited<ReturnType<typeof structured.execute>>>().toEqualTypeOf<{
        items: string[];
        meta: { note?: string | undefined };
    }>();
    expect(structured.serverResultSchema.parse({ items: [], meta: {} })).toEqual({ items: [], meta: {} });
    expect(() => structured.serverResultSchema.parse({ items: [42], meta: {} })).toThrow();
    expect(() => structured.serverResultSchema.parse({ items: [], meta: [] })).toThrow();
    expect(() => store.prepare({ title: 'x'.repeat(101) })).toThrow();
    expect(() => store.prepare({ title: 'Valid', reference: 'not-a-uuid' })).toThrow();
    expect(() => store.prepare({ title: '😀'.repeat(100) })).not.toThrow();

    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: generated.mutators,
        context: { user_id: 'alice' },
        userID: 'generated-controller',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const request = store.prepare({ title: 'Generated', done: undefined });
        expect(Object.hasOwn(request.args.input, 'done')).toBe(false);
        const created = await zero.mutate(request).client;
        expect(created.type).toBe('success');
        if (created.type !== 'success') throw new Error('Native prediction failed.');
        const { id } = store.clientResultSchema.parse(created.data);
        expect(id).toBe(request.args.prepared.ids.s0);
        expect((await zero.run(createBuilder(schema).tasks.where('id', id).one()))?.done).toBe(false);
        const queryInput: InferQueryInput<typeof index.tasks> = { title: 'Generated' };
        const rows: InferQueryResult<typeof index.tasks> = await zero.run(index.tasks(queryInput));
        expect(rows.map((row) => row.id)).toEqual([id]);
        expectTypeOf(rows[0]!.created_at).toEqualTypeOf<number | null>();
        expectTypeOf<InferLiveQueryResult<typeof index.tasks>[number]['created_at']>().toEqualTypeOf<Date | null>();
        expectTypeOf<InferQueryInput<typeof choices.tasks>['input']['status']>().toEqualTypeOf<
            'active' | 'completed' | undefined
        >();
        const filtered = choices.tasks.live({ input: { status: 'active', priority: 7 }, __window: 8 });
        expect(filtered.input.options).toEqual({
            direction: ['asc', 'desc'],
            priority: [2, 7],
            status: ['active', 'completed'],
        });
        expect(filtered.input.apply({ status: 'completed' })).toEqual({ input: { status: 'completed' } });
        expect(() => filtered.input.apply({ status: 'invalid' })).toThrow();
        expect(() => filtered.input.apply({ priority: 3 })).toThrow();
        expect(() => index.tasks.live(queryInput).input.apply({ title: 'other' })).toThrow();
        expectTypeOf<InferMutationInput<typeof storeAction>['title']>().toEqualTypeOf<string>();
        expectTypeOf<InferMutationResult<typeof storeAction>>().toEqualTypeOf<{ id: string }>();
        const single: InferQueryResult<typeof show.task> = await zero.run(show.task({ task: id }));
        expect(single?.id).toBe(id);
        expect(await zero.run(show.task({ task: 'missing' }))).toBeUndefined();
        // @ts-expect-error Query inputs reject incorrect scalar types at compile time and runtime.
        expect(() => index.tasks({ title: 42 })).toThrow();
        const observed = renderHook(() => ({ zero: useZero(), rows: useQuery(index.tasks(queryInput))[0] }), {
            wrapper: ({ children }: { children: ReactNode }) =>
                createElement(ZeroContext.Provider, { value: zero }, children),
        });
        try {
            expect(observed.result.current.zero).toBe(zero);
            expectTypeOf(observed.result.current.zero).toEqualTypeOf<Zero<typeof schema, undefined, ClientContext>>();
            await waitFor(() => expect(observed.result.current.rows.map((row) => row.id)).toEqual([id]));
        } finally {
            observed.unmount();
        }

        const changed = await zero.mutate(complete.prepare({ title: 'Validated' }, { task: id })).client;
        expect(changed).toEqual({ type: 'success', data: { before: false, after: true } });
        expect((await zero.run(createBuilder(schema).tasks.where('id', id).one()))?.done).toBe(true);
        expect(await zero.mutate(complete.prepare({ title: 'Missing' }, { task: 'missing' })).client).toEqual({
            type: 'success',
        });
        expect(await zero.mutate(noResult.prepare({ title: 'Void' })).client).toEqual({ type: 'success' });
        expect(await zero.mutate(authoritative.prepare({ title: 'Server only' })).client).toEqual({ type: 'success' });
        const projected = generated.actions[`${prefix}projected`];
        expectTypeOf<Awaited<ReturnType<typeof projected.execute>>>().toEqualTypeOf<{ id: string }>();
        expectTypeOf<ReturnType<typeof projected.clientResultSchema.parse>>().toEqualTypeOf<
            { readonly pending: boolean } | undefined
        >();
        expect(await zero.mutate(projected.prepare({ title: 'Custom' })).client).toEqual({
            type: 'success',
            data: { pending: true },
        });
        expect(() => projected.serverResultSchema.parse({ pending: true })).toThrow();
    } finally {
        await zero.close();
    }
});

it('generated conditional creation and explicit creation sites consume prepared identities', async () => {
    const generated = createControllerMutationBindings(schema, definitions, projections);
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: generated.mutators,
        context: { user_id: 'alice' },
        userID: 'generated-children',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const request = generated.actions[`${prefix}store`].prepare({ title: 'Parent', done: true });
        expect((await zero.mutate(request).client).type).toBe('success');
        const rows = await zero.run(createBuilder(schema).tasks);
        expect(rows).toHaveLength(2);
        expect(rows.find((row) => row.id === request.args.prepared.ids.s1)?.title).toBe(request.args.prepared.ids.s0);
        const explicit = generated.actions[`${prefix}explicit`].prepare({ title: 'Explicit' });
        const result = await zero.mutate(explicit).client;
        expect(result).toEqual({ type: 'success', data: { id: explicit.args.prepared.ids['key:task'] } });
    } finally {
        await zero.close();
    }
});

it('keyed repeated creation encodes dates before enqueue and preserves identity when input order changes', async () => {
    const generated = createControllerMutationBindings(schema, definitions, projections);
    const bulk = generated.actions[`${prefix}bulk`];
    const input = {
        items: [
            { key: 'second', title: 'Second', at: null },
            { key: 'first', title: 'First', at: new Date('2026-10-01T12:30:00.000Z') },
        ],
    };
    const request = bulk.prepare(input);
    expect(request.args.input.items).toEqual([
        { key: 'second', title: 'Second', at: null },
        { key: 'first', title: 'First', at: '2026-10-01T12:30:00.000Z' },
    ]);
    expect(() => bulk.prepare({ items: [{ key: 'bad', title: 'Invalid', at: new Date(NaN) }] })).toThrow();
    expect(() => bulk.prepare({ items: Array.from({ length: 21 }, () => input.items[0]!) })).toThrow();
    let expected: unknown;
    for (const reverse of [false, true]) {
        const zero = new Zero<typeof schema, undefined, ClientContext>({
            schema,
            mutators: generated.mutators,
            context: { user_id: 'alice' },
            userID: `keyed-${reverse}`,
            cacheURL: null,
            kvStore: 'mem',
            logLevel: 'error',
        });
        try {
            const reordered = bulk.prepare({ items: reverse ? [input.items[1]!, input.items[0]!] : input.items });
            const replay = reordered.mutator({ ...reordered.args, prepared: request.args.prepared });
            expect(await zero.mutate(replay).client).toEqual({ type: 'success' });
            const rows = await zero.run(createBuilder(schema).tasks.orderBy('title', 'asc'));
            expect(rows).toHaveLength(2);
            expect(rows[0]?.completed_at).toBe(Date.parse('2026-10-01T12:30:00.000Z'));
            expect(
                (await zero.run(dated.tasks({ input: { after: new Date('2026-10-01T12:30:00.000Z') } }))).map(
                    (row) => row.title,
                ),
            ).toEqual(['First']);
            expect(await zero.run(dated.tasks({ input: { after: new Date('2026-10-01T12:30:00.001Z') } }))).toEqual([]);
            const ids = rows.map((row) => row.id);
            if (reverse) expect(ids).toEqual(expected);
            else expected = ids;
            expect(await zero.mutate(bulk.prepare({ items: [input.items[0]!, input.items[0]!] })).client).toMatchObject(
                { type: 'error' },
            );
            expect(await zero.run(createBuilder(schema).tasks)).toHaveLength(2);
        } finally {
            await zero.close();
        }
    }
});
