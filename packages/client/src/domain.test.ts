// @vitest-environment jsdom
import { Zero, createBuilder } from '@rocicorp/zero';
import { expect, it } from 'vite-plus/test';
import { createDomainMutations } from './domain';
import { completeMutation } from './operation';
import { Task, schema, mutators } from '../../../tests/interop/generated';
import type { ClientContext } from './contract';

const contracts = { completeTasks: { ids: { type: 'string', array: true, maxItems: 3 } } } as const;

it('uses native atomic optimistic mutations with owned stable input and bounded validation', async () => {
    const zql = createBuilder(schema);
    const domain = createDomainMutations(schema, mutators, contracts, {
        optimistic: {
            completeTasks: async ({ tx, args, ctx }) => {
                for (const id of args.ids) {
                    const row = await tx.run(zql.tasks.where('id', id).one());
                    if (row?.user_id !== ctx.user_id) throw new Error('Unavailable');
                    await tx.mutate.tasks.update({ id, done: true });
                }
            },
        },
    });
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: domain.mutators,
        context: { user_id: 'alice' },
        userID: 'domain',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const created = Task.create().prepare({ title: 'Action' });
        await zero.mutate(created).client;
        const input = { ids: [created.args.id] };
        const request = domain.actions.completeTasks().prepare(input);
        input.ids[0] = 'changed-after-enqueue';
        expect(request.args.ids).toEqual([created.args.id]);
        expect(await zero.mutate(request).client).toEqual({ type: 'success' });
        expect((await zero.run(Task.query.one(created.args.id).request()))?.done).toBe(true);
        expect(await zero.mutate(request).client).toEqual({ type: 'success' });
        await zero.mutate(Task.update().prepare({ id: created.args.id, done: false })).client;
        expect(() => domain.actions.completeTasks().prepare({ ids: ['1', '2', '3', '4'] })).toThrow('bounded');
        // The supported native version resolves .server on local rejection too.
        // A failed second write must not leave the first speculative write behind.
        const rejected = domain.actions.completeTasks().prepare({ ids: [created.args.id, 'unavailable'] });
        await expect(completeMutation(zero.mutate(rejected))).rejects.toThrow('Unavailable');
        expect((await zero.run(Task.query.one(created.args.id).request()))?.done).toBe(false);
    } finally {
        await zero.close();
    }
});

it('registered actions have no optimistic effects unless explicitly supplied', async () => {
    const domain = createDomainMutations(schema, mutators, contracts);
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: domain.mutators,
        context: { user_id: 'alice' },
        userID: 'server-only',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const created = Task.create().prepare({ title: 'Server only' });
        await zero.mutate(created).client;
        await zero.mutate(domain.actions.completeTasks().prepare({ ids: [created.args.id] })).client;
        expect((await zero.run(Task.query.one(created.args.id).request()))?.done).toBe(false);
    } finally {
        await zero.close();
    }
});
