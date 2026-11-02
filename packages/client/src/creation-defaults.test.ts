// @vitest-environment jsdom
import { Zero, boolean, createBuilder, createSchema, number, string, table } from '@rocicorp/zero';
import { expect, expectTypeOf, it } from 'vite-plus/test';
import { z } from 'zod';
import {
    createControllerMutations,
    type Column,
    type ControllerMutationContract,
    type ControllerMutationOptions,
    type ModelContract,
    type MutationNode,
} from './index';

type Context = { readonly actor: string; readonly tenant: string; readonly enabled: boolean };
const schema = createSchema({
    tables: [
        table('tasks')
            .columns({
                id: string(),
                title: string(),
                owner: string(),
                tenant: string(),
                done: boolean(),
                note: string().optional(),
                created_at: number(),
                updated_at: number(),
            })
            .primaryKey('id'),
    ],
});
const column = (type: Column['type'], overrides: Partial<Column> = {}): Column => ({
    type,
    nullable: false,
    date: false,
    enum: null,
    hasDefault: false,
    default: null,
    ...overrides,
});
const literal = (value: Extract<MutationNode, { op: 'literal' }>['value']): MutationNode => ({ op: 'literal', value });
const model: ModelContract = {
    name: 'Task',
    table: 'tasks',
    key: 'id',
    columns: {
        id: column('string'),
        title: column('string', { hasDefault: true, default: 'database title' }),
        owner: column('string'),
        tenant: column('string'),
        done: column('boolean', { hasDefault: true, default: false }),
        note: column('string', { nullable: true }),
        created_at: column('number', { date: true }),
        updated_at: column('number', { date: true }),
    },
    writable: ['title', 'owner', 'done', 'note'],
    scopeFingerprint: 'test-scopes',
    creationDefaults: {
        op: 'merge',
        values: [
            literal({ title: 'global title', done: false }),
            {
                op: 'object',
                items: [
                    { key: 'owner', value: { op: 'context', field: 'actor' } },
                    { key: 'tenant', value: { op: 'context', field: 'tenant' } },
                ],
            },
            {
                op: 'conditional',
                condition: {
                    op: 'binary',
                    operator: '===',
                    left: { op: 'context', field: 'enabled' },
                    right: literal(true),
                },
                yes: literal({ title: 'enabled title', done: true }),
                no: literal({ title: 'disabled title', done: false }),
            },
        ],
    },
    relationConstraints: {},
    relations: {},
    idGenerator: 'uuid',
    timestamps: { created: 'created_at', updated: 'updated_at', deleted: null },
};
const name = 'm_0123456789abcdef0123456789abcdef';
const input = z.strictObject({
    title: z.string().optional(),
    owner: z.string().optional(),
    done: z.boolean().optional(),
    note: z.string().nullable().optional(),
});
const codec = {
    input,
    encode: (value: z.infer<typeof input>) => ({
        ...(value.title === undefined ? {} : { title: value.title }),
        ...(value.owner === undefined ? {} : { owner: value.owner }),
        ...(value.done === undefined ? {} : { done: value.done }),
        ...(value.note === undefined ? {} : { note: value.note }),
    }),
    serverResult: z.undefined(),
    clientResult: z.undefined(),
};
const data: MutationNode = { op: 'local', name: 'request' };
const recipe = (node: MutationNode): ControllerMutationContract => ({
    action: 'Tasks@write',
    version: 'defaults',
    optimism: 'generated',
    returns: false,
    parameters: { request: { kind: 'request', type: 'Request' } },
    sites: { task: 'uuid' },
    operations: [{ op: 'expression', value: node }],
});
const context: Context = { actor: 'actor', tenant: 'team', enabled: true };
const setup = (contract: ControllerMutationContract) => {
    const runtime = createControllerMutations<typeof schema, Context>(
        schema,
        { Task: model },
        { [name]: contract },
        { [name]: codec },
    );
    const zero = new Zero<typeof schema, undefined, Context>({
        schema,
        mutators: runtime.mutators,
        context,
        userID: 'session-id-not-context',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    return { zero, action: runtime.bind(name, codec), query: createBuilder(schema).tasks };
};

it('evaluates merged and conditional model defaults; explicit create values win, including false and null', async () => {
    const { zero, action, query } = setup(recipe({ op: 'create', model: 'Task', site: 'task', data }));
    try {
        const first = action.prepare({});
        expect(await zero.mutate(first).client).toEqual({ type: 'success' });
        expect(await zero.run(query.where('id', first.args.prepared.ids.task).one())).toMatchObject({
            id: first.args.prepared.ids.task,
            title: 'enabled title',
            owner: 'actor',
            tenant: 'team',
            done: true,
            note: null,
            created_at: first.args.prepared.timestamp,
            updated_at: first.args.prepared.timestamp,
        });
        const override = action.prepare({ title: 'explicit', owner: 'other', done: false, note: null });
        expect(await zero.mutate(override).client).toEqual({ type: 'success' });
        expect(await zero.run(query.where('id', override.args.prepared.ids.task).one())).toEqual(
            expect.objectContaining({ title: 'explicit', owner: 'other', done: false, note: null, tenant: 'team' }),
        );
        expect(model.creationDefaults).toEqual(expect.objectContaining({ op: 'merge' }));
    } finally {
        await zero.close();
    }
});

it('evaluates nested context, concatenation, list, get, and local nodes in effective creation plans', async () => {
    const defaults: MutationNode = {
        op: 'merge',
        values: [
            model.creationDefaults,
            {
                op: 'object',
                items: [
                    {
                        key: 'owner',
                        value: {
                            op: 'binary',
                            operator: '.',
                            left: { op: 'context', field: 'actor' },
                            right: literal('-owner'),
                        },
                    },
                    {
                        key: 'tenant',
                        value: {
                            op: 'get',
                            base: { op: 'list', items: [{ key: null, value: { op: 'context', field: 'tenant' } }] },
                            key: literal(0),
                        },
                    },
                    { key: 'note', value: { op: 'get', base: data, key: literal('title') } },
                ],
            },
        ],
    };
    const { zero, action, query } = setup(recipe({ op: 'create', model: 'Task', site: 'task', data, defaults }));
    try {
        expect(await zero.mutate(action.prepare({ title: 'input title' })).client).toEqual({ type: 'success' });
        expect(await zero.run(query.one())).toEqual(
            expect.objectContaining({ owner: 'actor-owner', tenant: 'team', note: 'input title' }),
        );
    } finally {
        await zero.close();
    }
});

it('treats operation defaults as the full effective plan rather than merging removed global defaults back in', async () => {
    const defaults: MutationNode = {
        op: 'conditional',
        condition: literal('0'),
        yes: { op: 'context', field: 'must-not-evaluate' },
        no: {
            op: 'merge',
            values: [literal({ owner: 'root-owner', tenant: 'root-tenant' }), literal({ done: false })],
        },
    };
    const { zero, action, query } = setup(recipe({ op: 'create', model: 'Task', site: 'task', data, defaults }));
    try {
        expect(await zero.mutate(action.prepare({})).client).toEqual({ type: 'success' });
        expect(await zero.run(query.one())).toEqual(
            expect.objectContaining({
                title: 'database title',
                owner: 'root-owner',
                tenant: 'root-tenant',
                done: false,
            }),
        );
    } finally {
        await zero.close();
    }
});

for (const op of ['insertUnlessExists', 'upsertUnique'] as const) {
    it(`${op} applies defaults only on its create branch, never to an existing row`, async () => {
        const contract = recipe({
            op,
            model: 'Task',
            unique: literal({ title: 'unique' }),
            site: 'task',
            data,
            defaults: {
                op: 'conditional',
                condition: { op: 'has', base: data, key: literal('note') },
                yes: { op: 'context', field: 'must-not-evaluate-on-update' },
                no: model.creationDefaults,
            },
        });
        const { zero, action, query } = setup(contract);
        try {
            const first = action.prepare({ owner: 'original', done: false });
            expect(await zero.mutate(first).client).toEqual({ type: 'success' });
            expect(await zero.run(query.one())).toEqual(
                expect.objectContaining({ owner: 'original', done: false, tenant: 'team' }),
            );
            const next = action.prepare({ note: 'updated' });
            expect(await zero.mutate(next).client).toEqual({ type: 'success' });
            expect(await zero.run(query.one())).toEqual(
                expect.objectContaining({
                    id: first.args.prepared.ids.task,
                    owner: 'original',
                    done: false,
                    note: op === 'upsertUnique' ? 'updated' : null,
                    created_at: first.args.prepared.timestamp,
                }),
            );
        } finally {
            await zero.close();
        }
    });
}

it('does not stamp model creation defaults onto plain updates', async () => {
    const contract = recipe({ op: 'create', model: 'Task', site: 'task', data });
    const updateName = 'm_11111111111111111111111111111111';
    const update = {
        ...recipe({ op: 'update', record: { op: 'local', name: 'task' }, data }),
        parameters: {
            ...contract.parameters,
            task: { kind: 'model', type: 'Task' },
        },
    };
    const runtime = createControllerMutations<typeof schema, Context>(
        schema,
        { Task: model },
        { [name]: contract, [updateName]: update },
        { [name]: codec, [updateName]: codec },
    );
    const zero = new Zero<typeof schema, undefined, Context>({
        schema,
        mutators: runtime.mutators,
        context,
        userID: 'updates',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const first = runtime.bind(name, codec).prepare({ owner: 'original', done: false });
        await zero.mutate(first).client;
        const next = runtime
            .bind(updateName, codec)
            .prepare({ note: 'changed' }, { task: first.args.prepared.ids.task! });
        expect(await zero.mutate(next).client).toEqual({ type: 'success' });
        expect(await zero.run(createBuilder(schema).tasks.one())).toEqual(
            expect.objectContaining({
                owner: 'original',
                done: false,
                note: 'changed',
                created_at: first.args.prepared.timestamp,
            }),
        );
    } finally {
        await zero.close();
    }
});

it('threads the application schema and context through custom projections and native actions', async () => {
    const custom: ControllerMutationOptions<{}, undefined, undefined, typeof schema, Context> = {
        input: z.strictObject({}),
        encode: () => ({}),
        serverResult: z.undefined(),
        clientResult: z.undefined(),
        optimistic: async ({ tx, ctx }) => {
            expectTypeOf(ctx.actor).toEqualTypeOf<string>();
            expectTypeOf(ctx.enabled).toEqualTypeOf<boolean>();
            await tx.mutate.tasks.insert({
                id: 'custom',
                title: ctx.actor,
                owner: ctx.actor,
                tenant: ctx.tenant,
                done: ctx.enabled,
                created_at: 0,
                updated_at: 0,
            });
        },
    };
    const runtime = createControllerMutations<typeof schema, Context>(
        schema,
        { Task: model },
        { [name]: { ...recipe(literal(null)), optimism: 'custom' } },
        { [name]: custom },
    );
    const zero = new Zero<typeof schema, undefined, Context>({
        schema,
        mutators: runtime.mutators,
        context,
        userID: 'custom',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        expect(await zero.mutate(runtime.bind(name, custom).prepare({})).client).toEqual({ type: 'success' });
        expect(await zero.run(createBuilder(schema).tasks.one())).toEqual(
            expect.objectContaining({ title: 'actor', done: true }),
        );
    } finally {
        await zero.close();
    }
});
