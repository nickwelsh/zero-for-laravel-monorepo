// @vitest-environment jsdom
import { Zero, createBuilder, defineMutatorWithType, defineMutatorsWithType } from '@rocicorp/zero';
import { expect, it } from 'vite-plus/test';
import { z } from 'zod';
import { schema, definitions } from '../../../tests/interop/generated';
import { createControllerMutations, type ControllerMutationContract, type MutationNode } from './controller-mutation';
import type { ClientContext } from './contract';
import * as assignment from '../../../tests/interop/assignment.generated';

const unique: MutationNode = { op: 'literal', value: { title: 'preference' } };
const contract = (op: 'insertUnlessExists' | 'upsertUnique', done: boolean): ControllerMutationContract => ({
    action: 'Preferences@write',
    version: 'preferences',
    optimism: 'generated',
    returns: true,
    parameters: {},
    sites: { preference: 'uuid' },
    operations: [
        {
            op: 'expression',
            value: { op, model: 'Task', unique, data: { op: 'literal', value: { done } }, site: 'preference' },
        },
        { op: 'set', name: 'row', value: { op: 'findUnique', model: 'Task', unique } },
        {
            op: 'return',
            value: { op: 'get', base: { op: 'local', name: 'row' }, key: { op: 'literal', value: 'done' } },
        },
    ],
});
const codec = { input: z.object({}), encode: () => ({}), serverResult: z.boolean(), clientResult: z.boolean() };
const subscribeName = 'm_11111111111111111111111111111111';
const unsubscribeName = 'm_22222222222222222222222222222222';

it('native transaction reads see writes, conflicting inserts preserve opt outs, and explicit upserts change only requested fields', async () => {
    const layer = createControllerMutations(
        schema,
        definitions,
        {
            [subscribeName]: contract('insertUnlessExists', true),
            [unsubscribeName]: contract('upsertUnique', false),
        },
        { [subscribeName]: codec, [unsubscribeName]: codec },
    );
    const subscribe = layer.bind(subscribeName, codec),
        unsubscribe = layer.bind(unsubscribeName, codec);
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: layer.mutators,
        context: { user_id: 'alice' },
        userID: 'preferences',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const first = subscribe.prepare({});
        expect(await zero.mutate(first).client).toEqual({ type: 'success', data: true });
        const initial = await zero.run(createBuilder(schema).tasks.one());
        expect(initial?.id).toBe(first.args.prepared.ids.preference);
        expect(initial?.created_at).toBe(first.args.prepared.timestamp);
        expect(await zero.mutate(unsubscribe.prepare({})).client).toEqual({ type: 'success', data: false });
        const competing = subscribe.prepare({});
        expect(competing.args.prepared.ids.preference).not.toBe(initial?.id);
        expect(await zero.mutate(competing).client).toEqual({ type: 'success', data: false });
        expect(await zero.run(createBuilder(schema).tasks)).toEqual([
            expect.objectContaining({
                id: initial?.id,
                done: false,
                user_id: 'alice',
                created_at: initial?.created_at,
            }),
        ]);
    } finally {
        await zero.close();
    }
});

it('generated assignment reads the current assignee on repeated execution and preserves known opt outs', async () => {
    const generated = assignment.createControllerMutationBindings(assignment.schema, assignment.definitions);
    const assign = generated.actions['ZeroForLaravel\\Tests\\Fixtures\\PreferenceMutationController@assign'];
    const seed = defineMutatorsWithType<typeof assignment.schema>()({
        seed: defineMutatorWithType<typeof assignment.schema, ClientContext>()(z.string(), async ({ tx, args }) => {
            await tx.mutate.tasks.upsert({
                id: 'issue',
                title: 'Assignment',
                user_id: 'actor',
                done: false,
                list_id: args,
            });
        }),
        optOut: defineMutatorWithType<typeof assignment.schema, ClientContext>()(async ({ tx }) => {
            await tx.mutate.mutation_preferences.insert({
                id: 'optout',
                issue: 'issue',
                recipient: 'B',
                subscribed: false,
                note: 'explicit',
            });
        }),
    });
    const prepared = assign.prepare({ list_id: 'B' }, { task: 'issue' });
    // Independent transaction snapshots model replay before and after an
    // authoritative reassignment; prior speculative writes are not retained.
    for (const previous of ['A', 'C']) {
        const zero = new Zero<typeof assignment.schema, undefined, ClientContext>({
            schema: assignment.schema,
            mutators: { ...seed, ...generated.mutators },
            context: { user_id: 'actor' },
            userID: 'assignment',
            cacheURL: null,
            kvStore: 'mem',
            logLevel: 'error',
        });
        const query = createBuilder(assignment.schema);
        try {
            expect(await zero.mutate(prepared).client).toEqual({ type: 'success' });
            expect(await zero.run(query.mutation_preferences)).toEqual([]);
            await zero.mutate(seed.seed(previous)).client;
            await zero.mutate(seed.optOut()).client;
            expect(await zero.mutate(assign.prepare({}, { task: 'issue' })).client).toEqual({
                type: 'success',
                data: { id: 'issue' },
            });
            expect((await zero.run(query.tasks.one()))?.list_id).toBe(previous);
            expect(await zero.mutate(prepared).client).toEqual({ type: 'success', data: { id: 'issue' } });
            expect((await zero.run(query.mutation_preferences.where('recipient', 'B').one()))?.subscribed).toBe(false);
            expect(await zero.run(query.mutation_preferences.where('recipient', previous).one())).toEqual(
                expect.objectContaining({
                    id: prepared.args.prepared.ids['key:unsubscribe'],
                    subscribed: false,
                }),
            );
            expect((await zero.run(query.tasks.one()))?.list_id).toBe('B');
            expect(await zero.run(query.mutation_preferences.where('recipient', 'B'))).toEqual([
                expect.objectContaining({ id: 'optout', note: 'explicit', subscribed: false }),
            ]);
            await zero.mutate(assign.prepare({ list_id: null }, { task: 'issue' })).client;
            expect((await zero.run(query.tasks.one()))?.list_id).toBeNull();
        } finally {
            await zero.close();
        }
    }
});
