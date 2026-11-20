// @vitest-environment jsdom
import { defineForm } from '@formbraid/core';
import { createBuilder, Zero } from '@rocicorp/zero';
import { ZeroContext } from '@rocicorp/zero/react';
import { act, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { expect, expectTypeOf, it, vi } from 'vite-plus/test';
import { schema, definitions, type Context } from '../../../tests/interop/generated';
import { createControllerMutationBindings } from '../../../tests/interop/redirect-mutations.generated';
import { createControllerForm } from './formbraid';
import { useControllerMutation } from './react/controller-mutation';

const prefix = 'ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@' as const;
const layer = createControllerMutationBindings<typeof schema, Context>(schema, definitions, {
    'ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected': async () => undefined,
});
const store = layer.actions[`${prefix}store`];

it('replays checked redirects as data with stable IDs and no navigation effects', async () => {
    const origin = window.location.href;
    const prepared = store.prepare({ title: 'Open', open_after_saving: true });
    const id = prepared.args.prepared.ids.s0!;
    for (const userID of ['submitter', 'replay']) {
        const zero = new Zero<typeof schema, undefined, Context>({
            schema,
            mutators: layer.mutators,
            context: { user_id: 'alice' },
            userID,
            cacheURL: null,
            kvStore: 'mem',
            logLevel: 'error',
        });
        try {
            const expected = {
                type: 'success',
                data: { kind: 'visit', route: 'todos.show', parameters: { todo: id } },
            };
            expect(await zero.mutate(prepared).client).toEqual(expected);
            const bound = layer.actions[`${prefix}bound`];
            expect(await zero.mutate(bound.prepare({}, { todo: 'not-cached' })).client).toEqual({ type: 'success' });
            expect(await zero.mutate(bound.prepare({}, { todo: id })).client).toEqual(expected);
            for (const flag of [false, undefined]) {
                expect(await zero.mutate(store.prepare({ title: 'Stay', open_after_saving: flag })).client).toEqual({
                    type: 'success',
                    data: { kind: 'stay' },
                });
            }
            for (const method of ['slug', 'confirmed', 'disabled'] as const) {
                const action = layer.actions[`${prefix}${method}`];
                expect(action.navigateAfter).toBe(method === 'slug' ? 'local' : 'server');
                const request = action.prepare({ title: method });
                expect(await zero.mutate(request).client).toEqual({ type: 'success' });
                const row = await zero.run(createBuilder(schema).tasks.where('id', request.args.prepared.ids.s0).one());
                if (method === 'disabled') expect(row).toBeUndefined();
                else expect(row?.title).toBe(method);
            }
            expect(await zero.mutate(layer.actions[`${prefix}projected`].prepare({})).client).toEqual({
                type: 'success',
            });
            expect(window.location.href).toBe(origin);
        } finally {
            await zero.close();
        }
    }
});

it('low-level execution, the hook and Formbraid expose void rather than internal destinations', async () => {
    const native = {
        mutate: vi.fn(() => ({
            client: Promise.resolve({ type: 'success' as const }),
            server: Promise.resolve({ type: 'success' as const, data: { kind: 'stay' } }),
        })),
    };
    expectTypeOf<Awaited<ReturnType<typeof store.execute>>>().toEqualTypeOf<undefined>();
    expect(() => store.serverResultSchema.parse({ kind: 'stay' })).toThrow();
    expect(await store.execute(native, { title: 'Saved' })).toBeUndefined();
    const hook = renderHook(() => useControllerMutation(store), {
        wrapper: ({ children }: { children: ReactNode }) => (
            <ZeroContext.Provider value={native as unknown as Zero<typeof schema, undefined, Context>}>
                {children}
            </ZeroContext.Provider>
        ),
    });
    try {
        await act(async () => expect(await hook.result.current({ title: 'Saved' })).toBeUndefined());
        expect(hook.result.current.state.status).toBe('success');
    } finally {
        hook.unmount();
    }
    const form = createControllerForm(
        { ...store, action: `${prefix}store` },
        defineForm<{ title: string }>()({ id: 'redirect', defaultValues: { title: '' }, fields: {}, groups: {} }),
        () => ({ client: native, actions: layer.actions, preparation: {} }),
    );
    expect(
        await form.submission.submit(
            { title: 'Saved' },
            { signal: new AbortController().signal, onProgress: () => {} },
        ),
    ).toEqual({ status: 'success', value: undefined });
    expect(native.mutate).toHaveBeenCalledTimes(3);
});

it('declines a scoped create before writing when its trusted context is unavailable', async () => {
    const generated = createControllerMutationBindings(schema, definitions, {
        'ZeroForLaravel\\Tests\\Fixtures\\RedirectMutationController@projected': async () => undefined,
    });
    const zero = new Zero({
        schema,
        context: {},
        mutators: generated.mutators,
        userID: 'no-context',
        kvStore: 'mem',
        cacheURL: null,
        logLevel: 'error',
    });
    try {
        expect(
            await zero.mutate(generated.actions[`${prefix}store`].prepare({ title: 'Wait', open_after_saving: true }))
                .client,
        ).toEqual({ type: 'success' });
        expect(await zero.run(createBuilder(schema).tasks)).toEqual([]);
    } finally {
        await zero.close();
    }
});
