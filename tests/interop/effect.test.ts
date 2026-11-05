import { defineForm } from '@formbraid/core';
import * as Zero from '@rocicorp/zero';
import * as EffectSchema from 'effect/Schema';
import * as EffectSchemaIssue from 'effect/SchemaIssue';
import { transformSync } from 'esbuild';
import { execFileSync } from 'node:child_process';
import { posix } from 'node:path';
import { compileFunction } from 'node:vm';
import { expect, it, vi } from 'vite-plus/test';
import * as Client from '../../packages/client/src/index';
import { createControllerForm } from '../../packages/client/src/formbraid';
import { MutationResultError, validationFields } from '../../packages/client/src/operation';
import { schema, definitions, type Context } from './generated';
import type * as MutationController from './controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';
import type * as QueryController from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import type * as MutationBindings from '../../packages/client/src/controller-mutation.fixture';

// Execute the actual PHP output without maintaining a duplicate generated fixture tree.
const sources: Record<string, string> = JSON.parse(
    execFileSync('php', ['packages/laravel/tests/Fixtures/export-controller-mutations.php', 'public', 'effect'], {
        encoding: 'utf8',
    }),
);
const modules = new Map<string, { exports: unknown }>();
function load(path: string): unknown {
    let module = modules.get(path);
    if (!module) {
        module = { exports: {} };
        modules.set(path, module);
        const imports: Record<string, unknown> = {
            '@rocicorp/zero': Zero,
            '@nickwelsh/zero-for-laravel': Client,
            'effect/Schema': EffectSchema,
            'effect/SchemaIssue': EffectSchemaIssue,
            '../generated': { schema, definitions },
        };
        compileFunction(transformSync(sources[path]!, { loader: 'ts', format: 'cjs', target: 'es2022' }).code, [
            'require',
            'module',
        ])(
            (name: string): unknown =>
                imports[name] ?? load(posix.normalize(posix.join(posix.dirname(path), `${name}.ts`))),
            module,
        );
    }
    return module.exports;
}
const actions = load('ZeroForLaravel/Tests/Fixtures/ControllerMutationController.ts') as typeof MutationController;
const queries = load('ZeroForLaravel/Tests/Fixtures/QueryController.ts') as typeof QueryController;
const { createControllerMutationBindings } = load('generated.mutations.ts') as typeof MutationBindings;
const projections = { [actions.projected.action]: async () => ({ pending: true }) };

it('exports native Effect schemas while preserving synchronous validation and field errors', () => {
    const input = actions.store.inputSchema;
    if (!EffectSchema.isSchema(input)) throw new Error('Expected a native Effect schema');
    const composed = EffectSchema.Struct({ input });
    expect(EffectSchema.is(composed)({ input: { title: 'Composable' } })).toBe(true);
    expect(EffectSchema.is(composed)({ input: { title: 7 } })).toBe(false);
    expect(input.parse({ title: '😀'.repeat(100), done: undefined })).toEqual({
        title: '😀'.repeat(100),
        done: undefined,
    });
    for (const invalid of [{ title: '😀'.repeat(101) }, { title: 'Valid', extra: true }]) {
        expect(() => input.parse(invalid)).toThrow();
    }
    expect(actions.structured.serverResultSchema.parse({ items: [], meta: {} })).toEqual({ items: [], meta: {} });
    expect(() => actions.structured.serverResultSchema.parse({ items: [], meta: { extra: true } })).toThrow();
    const result = input['~standard'].validate({ title: 'Native Zero' });
    expect(result).not.toBeInstanceOf(Promise);
    expect(result).toEqual({ value: { title: 'Native Zero' } });

    try {
        actions.bulk.inputSchema.parse({ items: [{ key: 'a', title: 42, at: new Date(NaN) }] });
        throw new Error('Expected validation to fail');
    } catch (error) {
        expect(error).toHaveProperty('cause');
        expect(validationFields(error)).toMatchObject({
            'items.0.title': expect.any(Array),
            'items.0.at': expect.any(Array),
        });
    }
    expect(() => actions.noResult.serverResultSchema.parse(null)).toThrow();
    expect(actions.noResult.serverResultSchema.parse(undefined)).toBeUndefined();
    expect(actions.authoritative.serverResultSchema.parse(null)).toBeNull();
});

it('executes generated Effect mutations and queries on native Zero without changing preparation or wire dates', async () => {
    const generated = createControllerMutationBindings<typeof schema, Context>(schema, definitions, projections);
    const zero = new Zero.Zero<typeof schema, undefined, Context>({
        schema,
        mutators: generated.mutators,
        context: { user_id: 'alice' },
        userID: 'effect-generation',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        const store = generated.actions[actions.store.action];
        const request = store.prepare({ title: 'Effect task', done: undefined });
        expect(Object.hasOwn(request.args.input, 'done')).toBe(false);
        const outcome = await zero.mutate(request).client;
        expect(outcome.type).toBe('success');
        if (outcome.type !== 'success') throw new Error('Prediction failed');
        const { id } = store.clientResultSchema.parse(outcome.data);
        expect(id).toBe(request.args.prepared.ids.s0);
        expect((await zero.run(Zero.createBuilder(schema).tasks.where('id', id).one()))?.title).toBe('Effect task');
        expect((await zero.run(queries.index.tasks({ title: 'Effect task' }))).map((row) => row.id)).toEqual([id]);
        const dated = queries.dated.tasks({ input: { after: new Date('2024-02-29T12:34:56.123Z') } });
        expect(dated.args).toEqual({ input: { after: '2024-02-29T12:34:56.123Z' } });
        expect(await zero.run(dated)).toEqual([]);
        const filtered = queries.choices.tasks.live({ input: { priority: 7 }, __window: 3 });
        expect(filtered.input.apply({ priority: 2 })).toEqual({ input: { priority: 2 } });
        expect(() => filtered.input.apply({ priority: 3 })).toThrow();
        expect(() => queries.dated.tasks({ input: { after: new Date(NaN) } })).toThrow();
        // @ts-expect-error Both generators reject unknown public query fields.
        expect(() => queries.index.tasks({ title: 'Effect task', extra: true })).toThrow();
    } finally {
        await zero.close();
    }
});

it('maps Effect codec failures into Formbraid field errors and preserves committed-result failures', async () => {
    const generated = createControllerMutationBindings(schema, definitions, projections);
    const mutate = vi.fn();
    const definition = defineForm<{ title: string }>()({
        id: 'EffectTask',
        defaultValues: { title: '' },
        fields: {},
        groups: {},
    });
    const form = createControllerForm(actions.store, definition, () => ({
        client: { mutate },
        actions: generated.actions,
        preparation: {},
    }));
    const context = { signal: new AbortController().signal, onProgress: () => {} };
    expect(await form.submission.submit({ title: 'x'.repeat(101) }, context)).toMatchObject({
        status: 'invalid',
        errors: { title: expect.any(Array) },
    });
    expect(mutate).not.toHaveBeenCalled();
    mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({ type: 'success', data: null }),
    });
    const failure = await form.submission.submit({ title: 'Saved' }, context);
    expect(failure).toMatchObject({ status: 'failure', error: expect.any(MutationResultError) });
    if (failure.status !== 'failure') throw new Error('Expected a committed result failure');
    expect(failure.error).toHaveProperty('committed', true);
    expect(mutate).toHaveBeenCalledOnce();
});
