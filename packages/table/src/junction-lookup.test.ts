import { execFileSync } from 'node:child_process';
import { runInNewContext } from 'node:vm';
import * as zeroAPI from '@rocicorp/zero';
import { asQueryInternals } from '@rocicorp/zero/bindings';
import { transformSync } from 'esbuild';
import { expect, it } from 'vite-plus/test';
import { normalizeAST, type AST } from '../../../node_modules/@rocicorp/zero/out/zero-protocol/src/ast.js';
import type * as Contracts from '../../../tests/interop/generated';
import * as client from '../../client/src';
import * as table from './index';
import { TableSession } from './react';

function load(source: string, imports: Record<string, unknown>): unknown {
    const module = { exports: {} };
    runInNewContext(transformSync(source, { loader: 'ts', format: 'cjs' }).code, {
        module,
        require: (name: string) => imports[name],
    });
    return module.exports;
}

it('limits visible junctions on the wire before decoding, with one sentinel even if the first target is hidden', async () => {
    const fixture: { schema: string; descriptor: string; ast: AST; authorized: AST } = JSON.parse(
        execFileSync('php', ['tests/interop/export.php', 'junction-preview'], { encoding: 'utf8' }),
    );
    const imports = {
        '@rocicorp/zero': zeroAPI,
        '@nickwelsh/zero-table': table,
        '@nickwelsh/zero-for-laravel': client,
    };
    const contracts = load(fixture.schema, imports) as typeof Contracts;
    const { schema } = contracts;
    const { default: descriptor } = load(fixture.descriptor, { ...imports, './generated': contracts }) as {
        default: table.TableDescriptor<{ tags: readonly { id: string }[] }>;
    };
    const selection = descriptor.rows({ filter: null, sort: [], groupBy: [], path: [] });
    const builder = zeroAPI.createBuilder(schema);
    const request = selection.request() as { args: unknown; query: { fn(input: unknown): typeof builder.tasks } };
    const query = request.query.fn({ args: request.args, ctx: { user_id: 'alice' } });
    expect(asQueryInternals(query).ast).toEqual(normalizeAST(fixture.ast));

    const pivot = fixture.authorized.related![0]!.subquery;
    expect(pivot).toMatchObject({ table: 'task_tag', limit: 4, orderBy: [['tag_id', 'asc']], where: { op: 'EXISTS' } });
    if (pivot.where?.type !== 'correlatedSubquery')
        throw new Error('Expected target eligibility before the pivot limit');
    const policy = pivot.where.related.subquery.where;
    expect(pivot.related![0]!.subquery.where).toEqual(policy);
    expect(JSON.stringify(policy)).toContain('permission_index');
    expect(JSON.stringify(policy)).toContain('user:alice');

    const mutators = zeroAPI.defineMutatorsWithType<typeof schema>()({
        seed: zeroAPI.defineMutatorWithType<typeof schema>()(async ({ tx }) => {
            for (const id of ['overflow', 'exact', 'hidden-only'])
                await tx.mutate.tasks.insert({ id, title: id, user_id: 'alice', done: false });
            for (const id of ['5', '4', '3', '2', '1', '0-hidden']) {
                await tx.mutate.tags.insert({ id, name: id, user_id: id === '0-hidden' ? 'bob' : 'alice' });
                await tx.mutate.task_tag.insert({ task_id: 'overflow', tag_id: id });
                if (id < '4') await tx.mutate.task_tag.insert({ task_id: 'exact', tag_id: id });
                if (id === '0-hidden') await tx.mutate.task_tag.insert({ task_id: 'hidden-only', tag_id: id });
            }
        }),
    });
    const zero = new zeroAPI.Zero({
        schema,
        mutators,
        userID: 'alice',
        kvStore: 'mem',
        cacheURL: null,
        logLevel: 'error',
    });
    try {
        expect((await zero.mutate(mutators.seed()).client).type).toBe('success');
        const raw = await zero.run(query);
        expect(raw.map((row) => row.id)).toEqual(['exact', 'hidden-only', 'overflow']);
        // Neither hidden-target pivots nor pivots beyond the sentinel reach the decoder.
        expect(JSON.stringify(raw)).not.toContain('0-hidden');
        expect(JSON.stringify(raw)).not.toContain('"tag_id":"5"');
        const rows = selection.decode(raw);
        expect(rows[2]!.tags.map((tag) => tag.id)).toEqual(['1', '2', '3', '4']);
        expect(Object.keys(rows[2]!)).not.toContain(pivot.alias);
        const preview = new TableSession(descriptor).columns.find((column) => column.id === 'tags')!.preview!;
        expect(rows.map(preview)).toEqual([
            { value: ['1', '2', '3'], truncated: false },
            { value: [], truncated: false },
            { value: ['1', '2', '3'], truncated: true },
        ]);
    } finally {
        await zero.close();
    }
}, 30_000); // Includes PHP fixture boot, schema/descriptor generation and a native Zero run.
