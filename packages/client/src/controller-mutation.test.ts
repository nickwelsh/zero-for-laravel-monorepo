// @vitest-environment jsdom
import { Zero, createBuilder } from '@rocicorp/zero';
import { expect, it, vi } from 'vite-plus/test';
import { z } from 'zod';
import { schema, definitions } from '../../../tests/interop/generated';
import { createControllerMutations, mutationTruthy, type ControllerMutationContract } from './controller-mutation';
import type { ClientContext } from './contract';

const name = 'm_0123456789abcdef0123456789abcdef';
const contract: ControllerMutationContract = {
    action: 'Example@store',
    version: 'test-version',
    optimism: 'generated',
    returns: true,
    parameters: { request: { kind: 'request', type: 'ExampleRequest' } },
    sites: { s0: 'uuid' },
    operations: [
        {
            op: 'set',
            name: 'task',
            value: {
                op: 'create',
                model: 'Task',
                site: 's0',
                data: { op: 'validated', base: { op: 'local', name: 'request' } },
            },
        },
        {
            op: 'return',
            value: {
                op: 'object',
                items: [
                    {
                        key: 'id',
                        value: { op: 'get', base: { op: 'local', name: 'task' }, key: { op: 'literal', value: 'id' } },
                    },
                ],
            },
        },
    ],
};
const codecs = {
    input: z.object({ title: z.string() }),
    encode: (value: { title: string }) => value,
    serverResult: z.object({ id: z.string() }),
    clientResult: z.object({ id: z.string() }).optional(),
};

it('prepares stable identities outside replay and executes generated operations on native Zero', async () => {
    const layer = createControllerMutations(schema, definitions, { [name]: contract }, { [name]: codecs });
    const action = layer.bind(name, codecs);
    const input = { title: 'Prepared' };
    const request = action.prepare(input);
    input.title = 'Changed';
    expect(request.args.input.title).toBe('Prepared');
    expect(Object.isFrozen(request.args)).toBe(true);
    const fixedId = '784949de-4dd0-4fd1-9297-b9e43e7a9fb7';
    const now = new Date('2026-10-31T12:34:56.789Z');
    const preparation = { uuid: vi.fn(() => fixedId), now: vi.fn(() => now) };
    const prepared = action.prepare({ title: 'Overridden' }, {}, preparation);
    expect(prepared.args.prepared).toEqual({ ids: { s0: fixedId }, timestamp: now.getTime() });
    now.setFullYear(2030);
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: layer.mutators,
        context: { user_id: 'alice' },
        userID: 'controller-create',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const result = await zero.mutate(request).client;
        expect(result).toEqual({ type: 'success', data: { id: request.args.prepared.ids.s0 } });
        const row = await zero.run(createBuilder(schema).tasks.where('id', request.args.prepared.ids.s0).one());
        expect(row?.title).toBe('Prepared');
        expect(row?.created_at).toBe(request.args.prepared.timestamp);
        expect(row?.user_id).toBe('alice');
        expect(action.prepare({ title: 'Another' }).args.prepared.ids.s0).not.toBe(request.args.prepared.ids.s0);
        await zero.mutate(prepared).client;
        const overridden = await zero.run(createBuilder(schema).tasks.where('id', fixedId).one());
        expect(overridden?.created_at).toBe(Date.parse('2026-10-31T12:34:56.789Z'));
        expect(overridden?.updated_at).toBe(overridden?.created_at);
        expect(preparation.uuid).toHaveBeenCalledOnce();
        expect(preparation.now).toHaveBeenCalledOnce();
    } finally {
        await zero.close();
    }
});

it('validates prepared UUID and ULID overrides before native request creation', () => {
    for (const generator of ['uuid', 'ulid'] as const) {
        const layer = createControllerMutations(
            schema,
            definitions,
            { [name]: { ...contract, sites: { s0: generator } } },
            { [name]: codecs },
        );
        const action = layer.bind(name, codecs);
        const id = generator === 'uuid' ? '784949de-4dd0-4fd1-9297-b9e43e7a9fb7' : '01K8VBQ37R3H2MXJP9DDX85XY7';
        expect(action.prepare({ title: 'Valid' }, {}, { [generator]: () => id }).args.prepared.ids.s0).toBe(id);
        expect(action.prepare({ title: 'Default' }).args.prepared.ids.s0).toHaveLength(generator === 'uuid' ? 36 : 26);
        for (const invalid of ['123', id + '\n']) {
            expect(() => action.prepare({ title: 'Invalid' }, {}, { [generator]: () => invalid })).toThrow(
                'Invalid prepared identity',
            );
        }
        expect(() => action.prepare({ title: 'Invalid time' }, {}, { now: () => new Date(NaN) })).toThrow(
            'Invalid prepared timestamp',
        );
    }
});

it('missing bound data declines prediction before writes without rejecting the native intent', async () => {
    const missing = { ...contract, parameters: { task: { kind: 'model', type: 'App\\Task' } } };
    const layer = createControllerMutations(schema, definitions, { [name]: missing }, { [name]: codecs });
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: layer.mutators,
        context: { user_id: 'alice' },
        userID: 'controller-missing',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const request = layer.bind(name, codecs).prepare({ title: 'Missing' }, { task: 'missing' });
        expect(await zero.mutate(request).client).toEqual({ type: 'success' });
        expect(await zero.run(createBuilder(schema).tasks)).toEqual([]);
    } finally {
        await zero.close();
    }
});

it('disabled and explicitly custom recipes use the same native protocol and honest client results', async () => {
    for (const optimism of ['disabled', 'custom'] as const) {
        const config = { ...codecs, clientResult: z.undefined(), optimistic: async () => undefined };
        const layer = createControllerMutations(
            schema,
            definitions,
            { [name]: { ...contract, optimism, sites: {} } },
            { [name]: config },
        );
        const zero = new Zero<typeof schema, undefined, ClientContext>({
            schema,
            mutators: layer.mutators,
            context: { user_id: 'alice' },
            userID: optimism,
            cacheURL: null,
            kvStore: 'mem',
            logLevel: 'error',
        });
        try {
            expect(await zero.mutate(layer.bind(name, config).prepare({ title: 'No write' })).client).toEqual({
                type: 'success',
            });
            expect(await zero.run(createBuilder(schema).tasks)).toEqual([]);
        } finally {
            await zero.close();
        }
    }
    expect(() =>
        createControllerMutations(
            schema,
            definitions,
            { [name]: { ...contract, optimism: 'custom' } },
            { [name]: codecs },
        ),
    ).toThrow('projection');
});

it('does not treat PHP string zero or empty arrays as truthy and rejects unencoded dates', () => {
    for (const value of [null, false, 0, '0', '', [], {}]) expect(mutationTruthy(value)).toBe(false);
    for (const value of ['false', '00', [0], { value: null }]) expect(mutationTruthy(value)).toBe(true);
    const layer = createControllerMutations(schema, definitions, { [name]: contract }, { [name]: codecs });
    const unsafe = { ...codecs, encode: () => ({ title: new Date() }) as any };
    expect(() => layer.bind(name, unsafe).prepare({ title: 'Date' })).toThrow('encode dates');
});
