import { expect, it } from 'vite-plus/test';
import { execFileSync } from 'node:child_process';
import { runInNewContext } from 'node:vm';
import { createBuilder, defineQueriesWithType, defineQueryWithType } from '@rocicorp/zero';
import { asQueryInternals } from '@rocicorp/zero/bindings';
import { z } from 'zod';
import { transformSync } from 'esbuild';
// Version-pinned compatibility oracle: 1.11 canonicalizes condition/relationship
// order before returning an AST. PHP emits the equivalent unnormalized wire AST.
import { normalizeAST } from '../../node_modules/@rocicorp/zero/out/zero-protocol/src/ast.js';
import { schema, definitions } from './generated';
import { liveQuery, withQueryInputs } from '../../packages/client/src/live-query';
import { dated } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';

it('executes both generated controller definitions with empty, zero-string, and ordinary inputs', () => {
    const zql = createBuilder(schema);
    const generated = JSON.parse(execFileSync('php', ['tests/interop/export.php', 'controller'], { encoding: 'utf8' }));
    const sorted = runInNewContext(`(${generated.sorted.typescript})`, { zql }) as (input: {
        args: { direction: 'asc' | 'desc' };
    }) => typeof zql.tasks;
    const sortInput = runInNewContext(generated.sorted.schema, { z }) as z.ZodType;
    expect(sortInput.safeParse({ direction: 'random' }).success).toBe(false);
    for (const direction of ['asc', 'desc'] as const) {
        expect(sortInput.parse({ direction })).toEqual({ direction });
        expect(asQueryInternals(sorted({ args: { direction } })).ast).toEqual(
            asQueryInternals(zql.tasks.orderBy('title', direction)).ast,
        );
    }
    const factory = runInNewContext(`(${generated.typescript})`, { zql }) as (input: {
        args: { title: string };
    }) => typeof zql.tasks;
    const validator = runInNewContext(generated.schema, { z }) as z.ZodType;
    for (const invalid of [{}, { title: null }, { title: false }, { title: '0', user_id: 'other' }]) {
        expect(validator.safeParse(invalid).success).toBe(false);
    }
    for (const test of generated.cases) {
        expect(validator.parse(test.args)).toEqual(test.args);
        const query = factory({ args: test.args });
        const native = asQueryInternals(query);
        expect(native.ast).toEqual(normalizeAST(test.ast));
        expect(native.format).toEqual(test.format);
        expect(native.ast.where !== undefined).toBe(test.args.title !== '');
    }
    const filtered = runInNewContext(`(${generated.filtered.typescript})`, { zql }) as (input: {
        args: { title: string; showDone: boolean; window: number };
    }) => typeof zql.tasks;
    for (const test of generated.filtered.cases) {
        let expected = zql.tasks;
        if (!test.args.showDone) expected = expected.where('done', false);
        if (test.args.title !== '') expected = expected.where('title', 'LIKE', `%${test.args.title}%`);
        expected = expected.orderBy('id', 'asc').limit(test.args.window);
        const native = asQueryInternals(filtered({ args: test.args }));
        expect(native.ast).toEqual(asQueryInternals(expected).ast);
        expect(normalizeAST(test.ast)).toEqual(native.ast);
    }
    const expected = zql.tasks
        .where((eb) =>
            eb.not(
                eb.or(
                    eb.cmp('title', 'IN', ['0', 'hidden']),
                    eb.exists('comments', (q) => q.where('body', 'secret'), { flip: true }),
                ),
            ),
        )
        .whereExists('list', (q) => q.where('id', 'list-a'), { scalar: true })
        .whereExists('comments', { flip: false })
        .related('comments', (q) => q.where('body', '!=', '0').orderBy('id', 'desc').limit(2))
        .related('list')
        .orderBy('title', 'desc')
        .orderBy('id', 'asc')
        .start({ title: '0', id: 'task-a' }, { inclusive: false });
    const related = runInNewContext(`(${generated.relationships.typescript})`, { zql }) as (input: {
        args: { title: string };
    }) => typeof expected;
    const native = asQueryInternals(related({ args: { title: '0' } }));
    expect(native.ast).toEqual(asQueryInternals(expected).ast);
    expect(native.ast).toEqual(normalizeAST(generated.relationships.ast));
    expect(native.format).toEqual(generated.relationships.format);
    const window = runInNewContext(`(${generated.window.typescript})`, { zql }) as (input: {
        args: { __window?: number };
    }) => typeof zql.tasks;
    expect(asQueryInternals(window({ args: {} })).ast).toEqual(normalizeAST(generated.window.ast));
    expect(asQueryInternals(window({ args: { __window: 5 } })).ast).toEqual(
        asQueryInternals(zql.tasks.orderBy('title', 'desc').orderBy('id', 'asc').limit(5)).ast,
    );
    const source = execFileSync('php', ['tests/interop/export.php', 'controller-module'], { encoding: 'utf8' });
    const compiled = transformSync(source, { loader: 'ts', format: 'cjs', target: 'es2022' });
    const modules: Record<string, unknown> = {
        '@rocicorp/zero': { createBuilder, defineQueriesWithType, defineQueryWithType },
        '@nickwelsh/zero-for-laravel': { liveQuery, withQueryInputs },
        zod: { z },
        './generated': { schema, definitions },
    };
    const exported: {
        bindings: Record<string, (args: unknown) => { request(): { args: unknown }; decode(raw: unknown): unknown }>;
    } = { bindings: {} };
    const module = { exports: exported };
    runInNewContext(compiled.code, { require: (name: string) => modules[name], module });
    Object.assign(exported, module.exports);
    const binding = exported.bindings[generated.name]!;
    expect(() => binding({ title: '0', user_id: 'other' })).toThrow();
    const selected = binding({ title: '0' });
    expect(selected.request().args).toEqual({ title: '0' });
    expect(selected.decode([{ id: 'a', completed_at: 1700000000123 }])).toEqual([
        { id: 'a', completed_at: new Date('2023-11-14T22:13:20.123Z') },
    ]);
});

it('encodes public Date arguments before constructing native named query requests', () => {
    const first = dated.tasks({ input: { after: new Date('2023-11-14T22:13:20.123Z') } });
    const equivalent = dated.tasks({ input: { after: new Date('2023-11-14T23:13:20.123+01:00') } });
    expect(first.args).toEqual({ input: { after: '2023-11-14T22:13:20.123Z' } });
    expect(equivalent.args).toEqual(first.args);
    expect(() => dated.tasks({ input: { after: new Date(NaN) } })).toThrow();
});

it('applies authoritative visibility to controller roots, nested results, junctions, and boolean scopes', () => {
    const q = createBuilder(schema).tasks.where('user_id', 'alice').where('deleted_at', 'IS', null);
    const native = {
        root: q,
        related: q
            .related('list', (child) => child.where('user_id', 'alice'))
            .related('comments', (child) => child.where('user_id', 'alice').limit(3)),
        junction: q.whereExists('tags', (child) => child.where('user_id', 'alice').where('id', 'owned')),
        groups: q.where((eb) =>
            eb.or(
                eb.exists('tags', (child) => child.where('user_id', 'alice').where('name', 'Reading')),
                eb.exists('tags', (child) => child.where('user_id', 'alice').where('name', 'Other')),
            ),
        ),
        scope: q.where((eb) => eb.or(eb.cmp('done', true), eb.cmp('title', 'shared'))),
    };
    const php = JSON.parse(execFileSync('php', ['tests/interop/export.php', 'authorized'], { encoding: 'utf8' }));
    expect(Object.keys(php)).toEqual(Object.keys(native));
    for (const [name, query] of Object.entries(native)) {
        expect(normalizeAST(php[name]), name).toEqual(asQueryInternals(query).ast);
    }
});

it('matches immutable PHP declarations to the pinned native construction surface', () => {
    const q = createBuilder(schema).tasks;
    const native = {
        unbounded: q,
        zeroLimit: q.limit(0),
        one: q.where('id', 'task-a').one(),
        cursor: q
            .orderBy('title', 'desc')
            .orderBy('id', 'asc')
            .start({ title: '0', id: 'task-a' }, { inclusive: true })
            .limit(7),
        exclusive: q.orderBy('id', 'asc').start({ id: 'task-a' }),
        relationships: q
            .related('comments', (child) =>
                child.where('body', 'NOT ILIKE', '%private%').orderBy('id', 'desc').limit(3),
            )
            .related('list'),
        junction: q.related('tags', (child) => child.where('name', 'IN', ['0', 'work'])),
        exists: q.whereExists('comments', (child) => child.where('body', 'NOT LIKE', 'secret%'), { flip: false }),
        scalar: q.whereExists('list', (child) => child.where('id', 'list-a'), { scalar: true }),
        junctionExists: q.whereExists('tags', (child) => child.where('name', 'work'), { flip: true }),
        negation: q.where((eb) =>
            eb.not(
                eb.or(
                    eb.cmp('done', false),
                    eb.exists('comments', (child) => child.where('body', 'hidden')),
                ),
            ),
        ),
        literals: q.where((eb) =>
            eb.or(eb.cmpLit('0', '=', '0'), eb.and(eb.cmpLit(0, '!=', 1), eb.cmp('list_id', 'IS', null))),
        ),
        emptyGroups: q.where((eb) => eb.and(eb.or(), eb.cmp('done', true))),
        missing: q.where((eb) => eb.or(undefined, eb.cmp('id', undefined))),
        ...Object.fromEntries(
            ['=', '!=', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'ILIKE', 'NOT ILIKE'].map((op) => [
                op,
                q.where('title', op as '=', '0'),
            ]),
        ),
        IN: q.where('title', 'IN', ['0', 'two']),
        'NOT IN': q.where('title', 'NOT IN', ['0', 'two']),
        IS: q.where('list_id', 'IS', null),
        'IS NOT': q.where('list_id', 'IS NOT', null),
    };
    const php = JSON.parse(execFileSync('php', ['tests/interop/export.php', 'declarations'], { encoding: 'utf8' }));
    expect(new Set(Object.keys(php))).toEqual(new Set(Object.keys(native)));
    for (const [name, query] of Object.entries(native)) {
        // Only inspect construction metadata; the cases intentionally have different result cardinalities.
        const internals = asQueryInternals(query as typeof q);
        expect(normalizeAST(php[name].ast), name).toEqual(internals.ast);
        expect(php[name].format, name).toEqual(internals.format);
    }
});
