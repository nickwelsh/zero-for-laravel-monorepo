import { expect, it } from 'vite-plus/test';
import { execFileSync } from 'node:child_process';
import { runInNewContext } from 'node:vm';
import { createBuilder, defineQueriesWithType, defineQueryWithType } from '@rocicorp/zero';
import { asQueryInternals } from '@rocicorp/zero/bindings';
import { z } from 'zod';
import { transformSync } from 'esbuild';
// Version-pinned compatibility oracle: 1.11 canonicalizes condition/relationship
// order before returning an AST. PHP emits the equivalent unnormalized wire AST.
import {
    normalizeAST,
    astSchema,
    type AST,
    type Condition,
} from '../../node_modules/@rocicorp/zero/out/zero-protocol/src/ast.js';
import { schema, definitions } from './generated';
import { liveQuery, withQueryInputs } from '../../packages/client/src/live-query';
import { dated } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import {
    search,
    dated as standaloneDated,
    detail,
    window as liveWindow,
    pages,
} from './controller-api/ZeroForLaravel/Tests/Fixtures/Module/TaskQueries';

it('executes standalone query exports with native ZQL, decoded selections and pagination', () => {
    const base = createBuilder(schema).tasks.where('deleted_at', 'IS', null);
    const ctx = { user_id: 'alice' };
    for (const title of [undefined, null, '', '0', 'search']) {
        const request = search({ input: title === undefined ? {} : { title } });
        const expected = (title ? base.where('title', 'LIKE', `%${title}%`) : base)
            .orderBy('title', 'desc')
            .orderBy('id', 'asc')
            .limit(20);
        expect(asQueryInternals(request.query.fn({ args: request.args, ctx })).ast).toEqual(
            asQueryInternals(expected).ast,
        );
    }
    const selected = search.live({ input: { title: '0' } });
    expect(selected.decode([{ id: 'a', title: '0', completed_at: 1700000000123 }])).toEqual([
        { id: 'a', title: '0', completed_at: new Date('2023-11-14T22:13:20.123Z') },
    ]);
    expect(selected.input.apply({ title: 'new' })).toEqual({ input: { title: 'new' } });
    expect(() => search({ input: { title: 'x'.repeat(256) } })).toThrow();
    const forged = { input: {}, __route: 'forged' };
    expect(() => search(forged)).toThrow();
    const datedRequest = standaloneDated({ input: { after: new Date('2023-11-14T22:13:20.123Z') } });
    expect(datedRequest.args).toEqual({ input: { after: '2023-11-14T22:13:20.123Z' } });
    const window = liveWindow.live({}).limit(7).request() as ReturnType<typeof liveWindow>;
    expect(asQueryInternals(window.query.fn({ args: window.args, ctx })).ast).toEqual(
        asQueryInternals(base.orderBy('title', 'desc').orderBy('id', 'asc').limit(7)).ast,
    );
    const cursor = { title: 'Cursor', id: 'a' };
    const page = pages.live({}).page(cursor).request() as ReturnType<typeof pages>;
    expect(asQueryInternals(page.query.fn({ args: page.args, ctx })).ast).toEqual(
        asQueryInternals(base.orderBy('title', 'desc').orderBy('id', 'asc').limit(3).start(cursor)).ast,
    );
    expect(detail.live({ id: 'a' }).decode({ id: 'a', title: 'Detail' })).toEqual({ id: 'a', title: 'Detail' });
});

it('executes both generated controller definitions with empty, zero-string, and ordinary inputs', () => {
    const zql = createBuilder(schema);
    const generated = JSON.parse(execFileSync('php', ['tests/interop/export.php', 'controller'], { encoding: 'utf8' }));
    // Public construction carries ordinary scopes; permission predicates are
    // installed privately by the server and checked in the authorized case.
    const tasks = zql.tasks.where('deleted_at', 'IS', null);
    const sorted = runInNewContext(`(${generated.sorted.typescript})`, { zql }) as (input: {
        args: { direction: 'asc' | 'desc' };
        ctx: { user_id: string };
    }) => typeof zql.tasks;
    const sortInput = runInNewContext(generated.sorted.schema, { z }) as z.ZodType;
    expect(sortInput.safeParse({ direction: 'random' }).success).toBe(false);
    for (const direction of ['asc', 'desc'] as const) {
        expect(sortInput.parse({ direction })).toEqual({ direction });
        expect(asQueryInternals(sorted({ args: { direction }, ctx: generated.context })).ast).toEqual(
            asQueryInternals(tasks.orderBy('title', direction)).ast,
        );
    }
    const factory = runInNewContext(`(${generated.typescript})`, { zql }) as (input: {
        args: { title: string };
        ctx: { user_id: string };
    }) => typeof zql.tasks;
    const validator = runInNewContext(generated.schema, { z }) as z.ZodType;
    for (const invalid of [{}, { title: null }, { title: false }, { title: '0', user_id: 'other' }]) {
        expect(validator.safeParse(invalid).success).toBe(false);
    }
    for (const test of generated.cases) {
        expect(validator.parse(test.args)).toEqual(test.args);
        const query = factory({ args: test.args, ctx: generated.context });
        const native = asQueryInternals(query);
        expect(native.ast).toEqual(normalizeAST(test.ast));
        expect(native.format).toEqual(test.format);
        expect(native.ast.where).toBeDefined();
    }
    const filtered = runInNewContext(`(${generated.filtered.typescript})`, { zql }) as (input: {
        args: { title: string; showDone: boolean; window: number };
        ctx: { user_id: string };
    }) => typeof zql.tasks;
    for (const test of generated.filtered.cases) {
        let expected = tasks;
        if (!test.args.showDone) expected = expected.where('done', false);
        if (test.args.title !== '') expected = expected.where('title', 'LIKE', `%${test.args.title}%`);
        expected = expected.orderBy('id', 'asc').limit(test.args.window);
        const native = asQueryInternals(filtered({ args: test.args, ctx: generated.context }));
        expect(native.ast).toEqual(asQueryInternals(expected).ast);
        expect(normalizeAST(test.ast)).toEqual(native.ast);
    }
    const expected = tasks
        .where('title', 'NOT IN', ['0', 'hidden'])
        .where((eb) => eb.not(eb.exists('comments', (q) => q.where('body', 'secret'))))
        .whereExists('list', (q) => q.where('id', 'list-a'), { scalar: true })
        .whereExists('comments', (q) => q, { flip: false })
        .related('comments', (q) => q.where('body', '!=', '0').orderBy('id', 'desc').limit(2))
        .related('list')
        .orderBy('title', 'desc')
        .orderBy('id', 'asc')
        .start({ title: '0', id: 'task-a' }, { inclusive: false });
    const related = runInNewContext(`(${generated.relationships.typescript})`, { zql }) as (input: {
        args: { title: string };
        ctx: { user_id: string };
    }) => typeof expected;
    const native = asQueryInternals(related({ args: { title: '0' }, ctx: generated.context }));
    expect(native.ast).toEqual(asQueryInternals(expected).ast);
    expect(native.ast).toEqual(normalizeAST(generated.relationships.ast));
    expect(native.format).toEqual(generated.relationships.format);
    const window = runInNewContext(`(${generated.window.typescript})`, { zql }) as (input: {
        args: { __window?: number };
        ctx: { user_id: string };
    }) => typeof zql.tasks;
    expect(asQueryInternals(window({ args: {}, ctx: generated.context })).ast).toEqual(
        normalizeAST(generated.window.ast),
    );
    expect(asQueryInternals(window({ args: { __window: 5 }, ctx: generated.context })).ast).toEqual(
        asQueryInternals(tasks.orderBy('title', 'desc').orderBy('id', 'asc').limit(5)).ast,
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
    // Permission predicates are private server ASTs, not public schema relationships.
    // Check those at every protected path, then compare the unchanged business
    // query against the native builder. Real subscriptions cover their decisions.
    function business(ast: AST): AST {
        function condition(value: Condition): Condition | undefined {
            if (value.type === 'correlatedSubquery') {
                if (value.related.system === 'permissions') {
                    const serialized = JSON.stringify(value);
                    expect(value.op).toBe('EXISTS');
                    expect(value.related.hidden).toBe(true);
                    expect(serialized).toContain('user:alice');
                    expect(serialized).toContain('"allow"');
                    expect(serialized).toContain('"deny"');
                    return undefined;
                }
                return { ...value, related: { ...value.related, subquery: business(value.related.subquery) } };
            }
            if (value.type === 'and' || value.type === 'or') {
                const conditions = value.conditions.map(condition).filter((item) => item !== undefined);
                return conditions.length === 1
                    ? conditions[0]
                    : conditions.length
                      ? { ...value, conditions }
                      : undefined;
            }
            return value;
        }
        if (['tasks', 'lists', 'tags', 'comments'].includes(ast.table)) {
            expect(JSON.stringify(ast.where), ast.table).toContain('"system":"permissions"');
        }
        const { where, related, ...rest } = ast;
        const filtered = where && condition(where);
        return {
            ...rest,
            ...(filtered ? { where: filtered } : {}),
            ...(related ? { related: related.map((child) => ({ ...child, subquery: business(child.subquery) })) } : {}),
        };
    }
    const q = createBuilder(schema).tasks.where('deleted_at', 'IS', null);
    const native = {
        root: q,
        related: q.related('list').related('comments', (child) => child.limit(3)),
        junction: q.whereExists('tags', (child) => child.where('id', 'owned')),
        groups: q.where((eb) =>
            eb.or(
                eb.exists('tags', (child) => child.where('name', 'Reading')),
                eb.exists('tags', (child) => child.where('name', 'Other')),
            ),
        ),
        scope: q.where((eb) => eb.or(eb.cmp('done', true), eb.cmp('title', 'shared'))),
    };
    const php = JSON.parse(execFileSync('php', ['tests/interop/export.php', 'authorized'], { encoding: 'utf8' }));
    expect(Object.keys(php)).toEqual(Object.keys(native));
    for (const [name, query] of Object.entries(native)) {
        expect(normalizeAST(business(astSchema.parse(php[name]))), name).toEqual(asQueryInternals(query).ast);
    }
});

it('matches independently cloned PHP declarations to the pinned native construction surface', () => {
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
        negation: q
            .where('done', '!=', false)
            .where((eb) => eb.not(eb.exists('comments', (child) => child.where('body', 'hidden')))),
        nullEquality: q.where('list_id', 'IS', null),
        nullInequality: q.where('list_id', 'IS NOT', null),
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
