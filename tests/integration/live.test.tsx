// @vitest-environment jsdom
/* oxlint-disable unicorn/no-array-sort -- ES2022; only newly allocated ID arrays are sorted. */
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import {
    mkdtempSync,
    mkdirSync,
    rmSync,
    readFileSync,
    existsSync,
    symlinkSync,
    writeFileSync,
    unlinkSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { randomBytes, randomUUID } from 'node:crypto';
import * as native from '@rocicorp/zero';
import { Zero, createBuilder, defineQueriesWithType, defineQueryWithType } from '@rocicorp/zero';
import { runInNewContext } from 'node:vm';
import { z } from 'zod';
import {
    createControllerMutations,
    type ControllerMutationContract,
} from '../../packages/client/src/controller-mutation';
import { ZeroProvider } from '@rocicorp/zero/react';
import { act, fireEvent, render, waitFor } from '@testing-library/react';
import { useEffect } from 'react';
import { useData, type Data } from '../../packages/client/src/react/data';
import { liveQuery, withQueryInputs } from '../../packages/client/src/live-query';
import { createInertiaAdapter } from '../../packages/client/src/react/inertia';
import type { index, show } from '../interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { expect, it, vi } from 'vite-plus/test';
import { schema as fixtureSchema, definitions, type Context } from '../interop/generated';
import {
    applyTableQuery,
    createTable,
    column,
    editors,
    type TableDescriptor,
    type FilterNode,
} from '../../packages/table/src';
import { DataTable, useDataTable } from '../../packages/table/src/react';
import { encodeDate } from '../../packages/client/src';

type LiveSchema = typeof fixtureSchema;

const enabled = process.env.ZERO_LIVE_TESTS === '1';
const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));
async function port() {
    const server = createServer();
    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', resolve));
    const address = server.address();
    if (!address || typeof address === 'string') throw new Error('No test port');
    await new Promise<void>((resolve, reject) => server.close((error) => (error ? reject(error) : resolve())));
    return address.port;
}
async function until(check: () => boolean | Promise<boolean>, message: string) {
    for (let i = 0; i < 300; i++) {
        if (await check()) return;
        await sleep(100);
    }
    throw new Error(message);
}
async function stop(child: ChildProcess | undefined) {
    if (!child || child.exitCode !== null || child.signalCode !== null) return;
    // Zero workers create their own process groups. Stop the full fixture tree,
    // not just the PHP launcher, before dropping its disposable database.
    const processes = execFileSync('ps', ['-eo', 'pid=,ppid='], { encoding: 'utf8' })
        .trim()
        .split('\n')
        .map((line) => line.trim().split(/\s+/).map(Number));
    const owned = new Set([child.pid!]);
    for (let size = 0; size !== owned.size;) {
        size = owned.size;
        for (const [pid, parent] of processes) if (owned.has(parent!)) owned.add(pid!);
    }
    process.kill(-child.pid!, 'SIGTERM');
    await Promise.race([new Promise<void>((resolve) => child.once('exit', () => resolve())), sleep(5000)]);
    for (const pid of owned) {
        try {
            process.kill(pid, 'SIGKILL');
        } catch (error) {
            if ((error as NodeJS.ErrnoException).code !== 'ESRCH') throw error;
        }
    }
    if (child.exitCode === null && child.signalCode === null)
        await new Promise<void>((resolve) => child.once('exit', () => resolve()));
}

it.skipIf(!enabled)(
    'replicates private-column-safe, authorized live queries and mutation rejection through actual Zero',
    async () => {
        const suffix = randomBytes(6).toString('hex');
        const database = `laravel_zero_test_live_${suffix}`;
        const appID = `test_${suffix}`;
        const publication = `laravel_zero_test_${suffix}`;
        const root = mkdtempSync(join(tmpdir(), 'zero-live-'));
        const phpPort = await port();
        const zeroPort = await port();
        const base = `http://127.0.0.1:${phpPort}`;
        const key = randomBytes(32).toString('hex');
        const env: NodeJS.ProcessEnv = {
            ...process.env,
            PGPASSWORD: process.env.ZERO_TEST_PG_PASSWORD ?? '',
            ZERO_INTEGRATION_ROOT: root,
            ZERO_PORT: String(zeroPort),
            ZERO_CHANGE_STREAMER_PORT: String(await port()),
            ZERO_LITESTREAM_PORT: String(await port()),
            APP_ENV: 'local',
            ZERO_LIVE_DATABASE: database,
            APP_URL: base,
            ZERO_API_KEY: key,
            ZERO_APP_ID: appID,
            ZERO_APP_PUBLICATIONS: publication,
        };
        const psql = (sql: string, target = database) =>
            execFileSync(
                'psql',
                [
                    '-h',
                    env.ZERO_TEST_PG_HOST ?? '127.0.0.1',
                    '-p',
                    env.ZERO_TEST_PG_PORT ?? '5432',
                    '-U',
                    env.ZERO_TEST_PG_USER ?? 'postgres',
                    '-d',
                    target,
                    '-v',
                    'ON_ERROR_STOP=1',
                    '-Atc',
                    sql,
                ],
                { env, encoding: 'utf8' },
            ).trim();
        let php: ChildProcess | undefined;
        let cache: ChildProcess | undefined;
        let zero: Zero<LiveSchema, undefined, Context> | undefined;
        let logs = '';
        let created = false;
        const WebSocket = globalThis.WebSocket;
        const sockets: WebSocket[] = [];
        const frames: Buffer[] = [];
        let auditWire: (() => void) | undefined;
        // Observe actual inbound binary WebSocket messages, before Zero decodes
        // or filters rows. No proxy, second cache, or mocked native client.
        globalThis.WebSocket = class extends WebSocket {
            constructor(url: string | URL, protocols?: string | string[]) {
                super(url, protocols);
                sockets.push(this);
                this.addEventListener('message', ({ data }: MessageEvent<unknown>) => {
                    if (data instanceof ArrayBuffer) frames.push(Buffer.from(new Uint8Array(data)));
                });
            }
        };
        try {
            psql(`CREATE DATABASE "${database}"`, 'postgres');
            created = true;
            for (const directory of [
                'bootstrap/cache',
                'storage/framework/views',
                'storage/framework/cache',
                'storage/logs',
            ])
                mkdirSync(join(root, directory), { recursive: true });
            execFileSync('php', ['tests/integration/app.php'], { env, stdio: 'pipe' });
            const generated = { exports: {} as { schema: LiveSchema } };
            runInNewContext(
                execFileSync('node_modules/.bin/esbuild', [join(root, 'generated.ts'), '--format=cjs'], {
                    encoding: 'utf8',
                }),
                { require: () => native, module: generated },
            );
            const schema = generated.exports.schema;
            expect(Object.keys(schema.tables)).toHaveLength(Object.keys(fixtureSchema.tables).length);
            expect(Object.keys(schema.tables)).toEqual(expect.arrayContaining(Object.keys(fixtureSchema.tables)));
            // Consume the deployed client manifest, not a second compilation
            // against a registry whose lazy relationships have since changed.
            const mutationModule = {
                exports: {} as { controllerMutationContracts: Record<string, ControllerMutationContract> },
            };
            runInNewContext(
                execFileSync('node_modules/.bin/esbuild', [join(root, 'generated.mutations.ts'), '--format=cjs'], {
                    encoding: 'utf8',
                }),
                {
                    require: (specifier: string) => (specifier === 'zod' ? { z } : { createControllerMutations }),
                    module: mutationModule,
                },
            );
            const contracts = mutationModule.exports.controllerMutationContracts;
            const name = Object.keys(contracts).find(
                (registration) => contracts[registration]!.action === 'FixtureMutations@store',
            )!;
            const updateName = Object.keys(contracts).find(
                (registration) => contracts[registration]!.action === 'FixtureMutations@update',
            )!;
            const codec = {
                input: z.object({ title: z.string() }),
                encode: (input: { title: string }) => input,
                serverResult: z.object({ id: z.string() }),
                clientResult: z.object({ id: z.string() }),
            };
            const updateCodec = { ...codec, serverResult: z.undefined(), clientResult: z.undefined() };
            const controllers = createControllerMutations<typeof schema, Context>(schema, definitions, contracts, {
                [name]: codec,
                [updateName]: updateCodec,
            });
            const store = controllers.bind(name, codec);
            const update = controllers.bind(updateName, updateCodec);
            const allMutators = controllers.mutators;
            // Compile outside jsdom; its Uint8Array differs from Node TextEncoder's.
            const compiled = execFileSync(
                'node_modules/.bin/esbuild',
                [join(root, 'generated.queries.ts'), '--format=cjs', '--target=es2022'],
                { encoding: 'utf8' },
            );
            const modules: Record<string, unknown> = {
                '@rocicorp/zero': native,
                '@nickwelsh/zero-for-laravel': { liveQuery, withQueryInputs },
                zod: { z },
                './generated': { schema, definitions },
            };
            const module = {
                exports: {} as {
                    actions: Record<
                        string,
                        (input: { direction: 'asc' | 'desc' }) => ReturnType<typeof index.tasks.live>
                    >;
                    bindings: Parameters<typeof createInertiaAdapter>[0];
                },
            };
            runInNewContext(compiled, { require: (specifier: string) => modules[specifier], module });
            const tableModule = { exports: {} as { default: TableDescriptor<{ id: string; title: string }> } };
            runInNewContext(
                execFileSync(
                    'node_modules/.bin/esbuild',
                    [join(root, 'tables/tasks.ts'), '--format=cjs', '--target=es2022'],
                    { encoding: 'utf8' },
                ),
                {
                    require: (specifier: string) =>
                        specifier === '../generated'
                            ? { schema, definitions }
                            : specifier === '@nickwelsh/zero-table'
                              ? { createTable, applyTableQuery }
                              : specifier === '@nickwelsh/zero-for-laravel'
                                ? { liveQuery, encodeDate }
                                : modules[specifier],
                    module: tableModule,
                },
            );
            const tableDescriptor = tableModule.exports.default;
            const tasks = module.exports.actions['ZeroForLaravel\\Tests\\Fixtures\\QueryController@sorted.tasks']!;
            const queryName = tasks({ direction: 'asc' }).request().query.queryName;
            const showTask = module.exports.actions[
                'ZeroForLaravel\\Tests\\Fixtures\\QueryController@show.task'
            ] as unknown as typeof show.task.live;
            const guardedName = showTask({ task: '019a0000-0000-7000-8000-000000000001' }).request().query.queryName;
            expect(
                psql(
                    `SELECT attnames::text FROM pg_publication_tables WHERE pubname='${publication}' AND tablename='tasks'`,
                ),
            ).not.toContain('secret');
            // Five business/pivot tables, seven private permission projections,
            // one safe access root and one private access-check projection.
            expect(psql(`SELECT count(*) FROM pg_publication_tables WHERE pubname='${publication}'`)).toBe('14');
            php = spawn(
                'php',
                [
                    '-d',
                    'display_errors=1',
                    '-d',
                    'log_errors=1',
                    '-d',
                    `error_log=${root}/php.log`,
                    '-S',
                    `127.0.0.1:${phpPort}`,
                    join(process.cwd(), 'tests/integration/app.php'),
                ],
                {
                    env,
                    detached: true,
                    stdio: ['ignore', 'pipe', 'pipe'],
                },
            );
            php.stderr?.on('data', (chunk) => {
                logs += String(chunk);
            });
            let probe: Response | undefined;
            await until(async () => {
                try {
                    probe = await fetch(`${base}/callbacks/zero/query`, { method: 'POST' });
                    return true;
                } catch {
                    return false;
                }
            }, 'Laravel callback server did not start');
            if (probe?.status !== 403)
                throw new Error(`Callback status ${probe?.status}: ${(await probe?.text())?.slice(0, 2000)}`);
            const batch = await fetch(`${base}/callbacks/zero/query`, {
                method: 'POST',
                headers: { 'X-Api-Key': key, Authorization: 'Bearer alice', 'Content-Type': 'application/json' },
                body: JSON.stringify([
                    'transform',
                    Array.from({ length: 257 }, (_, i) => ({
                        id: String(i),
                        name: queryName,
                        args: [{ direction: 'asc' }],
                    })),
                ]),
            });
            const transformed = (await batch.json()) as { queries: { ast?: unknown; error?: string }[] };
            expect(transformed.queries).toHaveLength(257);
            expect(transformed.queries[0]).toHaveProperty('ast');
            expect(transformed.queries.every((query) => query.ast && !query.error)).toBe(true);
            symlinkSync(join(process.cwd(), 'node_modules'), join(root, 'node_modules'), 'dir');
            cache = spawn('php', ['tests/integration/app.php', 'dev'], {
                env,
                detached: true,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            cache.stderr?.on('data', (chunk) => {
                logs += String(chunk);
            });
            cache.stdout?.on('data', (chunk) => {
                logs += String(chunk);
            });
            await until(async () => {
                if (cache?.exitCode !== null) throw new Error('Zero exited');
                try {
                    return (await fetch(`http://127.0.0.1:${zeroPort}/`)).ok;
                } catch {
                    return false;
                }
            }, 'Cache did not start');
            const alice = '019a0000-0000-7000-8000-000000000001';
            const bob = '019a0000-0000-7000-8000-000000000002';
            const third = '019a0000-0000-7000-8000-000000000003';
            psql(
                `INSERT INTO tasks (id,user_id,title,secret,created_at,updated_at) VALUES ('${alice}','alice','Initial','private',now(),now()),('${bob}','bob','Other owner','private',now(),now()),('${third}','alice','Zebra','private',now(),now())`,
            );
            zero = new Zero<typeof schema, undefined, Context>({
                schema,
                mutators: allMutators,
                userID: 'alice',
                auth: 'alice',
                context: { user_id: 'alice' },
                cacheURL: `http://127.0.0.1:${zeroPort}`,
                kvStore: 'mem',
                logLevel: 'error',
            });
            const view = zero.materialize(tasks({ direction: 'asc' }).request());
            let result: readonly { id: string; title: string }[] = [];
            let complete = false;
            const unsubscribe = view.addListener((rows, type) => {
                result = rows;
                complete = type === 'complete';
            });
            const builder = createBuilder(schema);
            const defineQueries = defineQueriesWithType<LiveSchema>();
            const defineQuery = defineQueryWithType<LiveSchema, Context>();
            const access = defineQueries({
                __zero_access_status: defineQuery(
                    ({
                        args,
                    }: {
                        args: {
                            generation: string;
                            bindings: { binding: string; query: string; args: { task: string } }[];
                        };
                        ctx: Context;
                    }) => builder.permission_access_results.where('generation', args.generation),
                ),
            });
            const watches: (() => void)[] = [];
            const generation = randomUUID();
            const binding = randomUUID();
            let aliceStatusID: string | undefined;
            function status(ids = [alice], current: string = randomUUID(), owner = zero!) {
                const args = {
                    generation: current,
                    bindings: ids.map((task, i) => ({
                        binding: i === 0 ? binding : randomUUID(),
                        query: guardedName,
                        args: { task },
                    })),
                };
                const selected = owner.materialize(access['__zero_access_status'](args), { ttl: 0 });
                const state = {
                    args,
                    complete: false,
                    rows: [] as readonly {
                        id: string;
                        generation: string;
                        binding: string;
                        state: string;
                        status: number | null;
                    }[],
                };
                const removeStatus = selected.addListener((rows, type) => {
                    state.rows = rows;
                    state.complete = type === 'complete';
                });
                watches.push(() => {
                    removeStatus();
                    selected.destroy();
                });
                return state;
            }
            async function transform(registration: string, args: object, actor = 'alice') {
                const response = await fetch(`${base}/callbacks/zero/query`, {
                    method: 'POST',
                    headers: { 'X-Api-Key': key, Authorization: `Bearer ${actor}`, 'Content-Type': 'application/json' },
                    body: JSON.stringify(['transform', [{ id: randomUUID(), name: registration, args: [args] }]]),
                });
                expect(response.status).toBe(200);
                const body = (await response.json()) as {
                    queries: { ast?: unknown; error?: string; details?: { status: number } }[];
                };
                expect(body.queries).toHaveLength(1);
                return body.queries[0]!;
            }
            try {
                await until(() => complete && result.length === 2, 'Authorized query never completed');
                // Exercise generated table queries on the real synchronized Zero
                // client, not a second invocation of the SQL compiler. Expected
                // membership is enumerated independently for asymmetric inputs.
                const parityIDs = Array.from(
                    { length: 8 },
                    (_, i) => `019a0000-0000-7000-8000-${String(i + 10).padStart(12, '0')}`,
                );
                const titles = [
                    'Alpha%_\\tail',
                    'alpha',
                    'é',
                    'e\u0301',
                    '0',
                    'false',
                    'Alpha%_\\tail',
                    'Alpha%_\\tail',
                ];
                const quote = (value: string) => `'${value.replaceAll("'", "''")}'`;
                psql(
                    `INSERT INTO tasks (id,user_id,title,done,completed_at,deleted_at) VALUES ${parityIDs.map((id, i) => `('${id}','${i === 6 ? 'bob' : 'alice'}',${quote(titles[i]!)},${i === 1},${i === 0 ? "'2026-01-01 00:00:00.123'" : i === 1 ? "'2026-01-01 01:00:00'" : 'NULL'},${i === 7 ? 'now()' : 'NULL'})`).join(',')}`,
                );
                const condition = (field: string, operator: string, value?: unknown): FilterNode =>
                    ({ kind: 'condition', field, operator, ...(value === undefined ? {} : { value }) }) as FilterNode;
                const date = '2025-12-31T19:00:00.123-05:00';
                const cases: [FilterNode, number[]][] = [
                    [condition('title', 'equals', 'Alpha%_\\tail'), [0]],
                    [condition('title', 'contains', '%_\\'), [0]],
                    [condition('title', 'startsWith', 'Alpha%_\\'), [0]],
                    [condition('title', 'endsWith', '\\tail'), [0]],
                    [condition('title', 'equals', 'alpha'), [1]],
                    [condition('title', 'equals', 'é'), [2]],
                    [condition('title', 'equals', 'e\u0301'), [3]],
                    [condition('title', 'equals', '0'), [4]],
                    [condition('title', 'equals', 'false'), [5]],
                    [condition('completed', 'equals', true), [1]],
                    [condition('date', 'equals', date), [0]],
                    [condition('date', 'gt', date), [1]],
                    [condition('date', 'gte', date), [0, 1]],
                    [condition('date', 'lt', '2026-01-01T01:00:00Z'), [0]],
                    [condition('date', 'lte', '2026-01-01T01:00:00Z'), [0, 1]],
                    [condition('date', 'notEquals', date), [1]],
                    [condition('date', 'in', [date, '2026-01-01T01:00:00Z']), [0, 1]],
                    [condition('date', 'isNotNull'), [0, 1]],
                    [
                        {
                            kind: 'or',
                            children: [condition('title', 'contains', '%_\\'), condition('title', 'equals', 'alpha')],
                        },
                        [0, 1],
                    ],
                ];
                for (const [filter, indices] of cases) {
                    const expected = indices.map((i) => parityIDs[i]!).sort();
                    const input = { filter, sort: [], groupBy: [], path: [], __window: 100 };
                    const selected = zero.materialize(
                        tableDescriptor.rows(input).request() as ReturnType<typeof tasks> extends { request(): infer R }
                            ? R
                            : never,
                        { ttl: 0 },
                    );
                    let ids: readonly string[] = [];
                    let finished = false;
                    const remove = selected.addListener((rows, type) => {
                        ids = (rows as readonly { id: string }[]).map((row) => row.id);
                        finished = type === 'complete';
                    });
                    try {
                        await until(
                            () => finished && JSON.stringify([...ids].sort()) === JSON.stringify(expected),
                            `Native table parity failed: ${JSON.stringify(filter)}; got ${ids.join(',')}`,
                        );
                        const metadata = await fetch(`${base}${tableDescriptor.capabilities.metadataURL}`, {
                            method: 'POST',
                            headers: {
                                Authorization: 'Bearer alice',
                                'Content-Type': 'application/json',
                                Accept: 'application/json',
                            },
                            body: JSON.stringify({
                                version: 1,
                                contract: tableDescriptor.capabilities.contract,
                                filter,
                                groupBy: ['done'],
                                directories: [{ path: [], after: null }],
                            }),
                        });
                        expect(metadata.status).toBe(200);
                        const snapshot = (await metadata.json()) as {
                            totalCount: number;
                            directories: { groups: { value: boolean; count: number }[] }[];
                        };
                        expect(snapshot.totalCount, JSON.stringify(filter)).toBe(expected.length);
                        expect(snapshot.directories[0]!.groups.reduce((sum, group) => sum + group.count, 0)).toBe(
                            expected.length,
                        );
                    } finally {
                        remove();
                        selected.destroy();
                    }
                }
                // Exercise the real generated descriptor and React query lifecycle.
                // Mocked native results cannot catch identity-owner render loops.
                function EditableTable() {
                    const session = useDataTable(tableDescriptor, {
                        initialState: {
                            groupBy: [],
                            filter: { kind: 'condition', field: 'title', operator: 'equals', value: 'Initial' },
                        },
                        columns: [column.text('title', { header: 'Title', editor: editors.text() })],
                        editing: { enabled: true, submit: async () => ({ status: 'accepted' }) },
                    });
                    return (
                        <DataTable.Root table={session}>
                            <DataTable.EditingStatus />
                            {session.items.flatMap((item) =>
                                item.kind === 'row'
                                    ? [
                                          <DataTable.Cell
                                              key={item.id}
                                              table={session}
                                              rowId={item.id}
                                              columnId="title"
                                          />,
                                      ]
                                    : [],
                            )}
                        </DataTable.Root>
                    );
                }
                const editingHost = render(
                    <ZeroProvider zero={zero}>
                        <EditableTable />
                    </ZeroProvider>,
                );
                try {
                    await waitFor(() => expect(editingHost.getByRole('gridcell', { name: 'Title' })).toBeDefined(), {
                        timeout: 10000,
                    });
                    fireEvent.doubleClick(editingHost.getByRole('gridcell', { name: 'Title' }));
                    await waitFor(() => expect(editingHost.getByRole('textbox', { name: 'Title' })).toBeDefined(), {
                        timeout: 10000,
                    });
                    fireEvent.change(editingHost.getByRole('textbox', { name: 'Title' }), {
                        target: { value: 'Unsaved draft' },
                    });
                    expect((editingHost.getByRole('textbox', { name: 'Title' }) as HTMLInputElement).value).toBe(
                        'Unsaved draft',
                    );
                    fireEvent.keyDown(editingHost.getByRole('textbox', { name: 'Title' }), { key: 'Escape' });
                    expect(editingHost.queryByRole('textbox')).toBeNull();
                    expect(editingHost.getByRole('gridcell', { name: 'Title' }).textContent).toBe('Initial');
                } finally {
                    editingHost.unmount();
                }
                // Identity reads ignore view membership, never mandatory visibility
                // or soft deletion. Their live result must revoke an individual row.
                for (const index of [0, 6, 7]) {
                    const id = parityIDs[index]!;
                    const selected = zero.materialize(
                        tableDescriptor
                            .rows({
                                filter: null,
                                sort: [],
                                groupBy: [],
                                path: [],
                                __window: 1,
                                __row: [id],
                                __refresh: randomUUID(),
                            })
                            .request() as ReturnType<typeof tasks> extends { request(): infer R } ? R : never,
                        { ttl: 0 },
                    );
                    let ids: readonly string[] = [];
                    let finished = false;
                    const remove = selected.addListener((rows, type) => {
                        ids = (rows as readonly { id: string }[]).map((row) => row.id);
                        finished = type === 'complete';
                    });
                    try {
                        const expected = index === 0 ? [id] : [];
                        await until(
                            () => finished && JSON.stringify(ids) === JSON.stringify(expected),
                            'Identity visibility did not match ownership and deletion',
                        );
                        if (index === 0) {
                            psql(`UPDATE tasks SET user_id='bob' WHERE id='${id}'`);
                            await until(() => finished && ids.length === 0, 'Identity read retained a revoked row');
                            psql(`UPDATE tasks SET user_id='alice' WHERE id='${id}'`);
                            await until(
                                () => finished && ids[0] === id,
                                'Identity read did not recover authorized membership',
                            );
                        }
                    } finally {
                        remove();
                        selected.destroy();
                    }
                }
                psql(`DELETE FROM tasks WHERE id IN (${parityIDs.map(quote).join(',')})`);
                await until(() => complete && result.length === 2, 'Parity fixture cleanup did not synchronize');
                expect(
                    psql(
                        "SELECT count(*) FROM pg_tables WHERE schemaname='public' AND tablename='permission_access_revision'",
                    ),
                ).toBe('0');
                const first = status([alice, bob, '019a0000-0000-7000-8000-000000000099'], generation);
                await until(
                    () => first.complete && first.rows.length === 3,
                    'Reserved production status query did not complete',
                );
                expect(first.rows.find((row) => row.binding === binding)).toMatchObject({
                    state: 'allowed',
                    status: null,
                });
                aliceStatusID = first.rows.find((row) => row.binding === binding)!.id;
                // The fixture's visibility scope conceals another owner's model
                // at route binding, before its captured Gate can disclose it.
                expect(first.rows.filter((row) => row.state === 'denied').map((row) => row.status)).toEqual([404, 404]);
                for (const row of first.rows) {
                    expect(Object.keys(row)).toHaveLength(5);
                    expect(Object.keys(row)).toEqual(
                        expect.arrayContaining(['binding', 'generation', 'id', 'state', 'status']),
                    );
                }
                const serverAST = JSON.stringify((await transform('__zero_access_status', first.args)).ast);
                expect(serverAST).toContain('"system":"permissions"');
                expect(serverAST).toContain('permission_access_checks');
                expect(serverAST).not.toContain('permission_access_revision');
                const initialStatus = [...first.rows];
                execFileSync('php', ['tests/integration/app.php', 'revoke-rollback'], { env, stdio: 'pipe' });
                await sleep(300);
                expect(first.rows).toEqual(initialStatus);
                expect(result).toHaveLength(2);

                // A committed unrelated grant must produce no access row patch,
                // Laravel check, or status change. A later business-row update is
                // the WAL catch-up barrier; this is not a sleep-only assertion.
                const unrelatedStart = frames.length;
                const unrelatedCallbacks = readFileSync(join(root, 'query-callbacks.log'), 'utf8');
                const unrelatedChecks = readFileSync(join(root, 'access-actors.log'), 'utf8');
                execFileSync('php', ['tests/integration/app.php', 'revoke-related'], { env, stdio: 'pipe' });
                psql(`UPDATE tasks SET title='Unrelated permission barrier' WHERE id='${alice}'`);
                await until(
                    () => result[0]?.title === 'Unrelated permission barrier',
                    'Unrelated change did not reach WAL barrier',
                );
                expect(first.rows).toEqual(initialStatus);
                expect(result).toHaveLength(2);
                expect(readFileSync(join(root, 'query-callbacks.log'), 'utf8')).toBe(unrelatedCallbacks);
                expect(readFileSync(join(root, 'access-actors.log'), 'utf8')).toBe(unrelatedChecks);
                const unrelatedWire = Buffer.concat(frames.slice(unrelatedStart)).toString('utf8');
                expect(unrelatedWire).toContain('Unrelated permission barrier');
                expect(unrelatedWire).not.toContain('permission_access_results');
                for (const row of initialStatus) expect(unrelatedWire).not.toContain(row.id);
                expect(await transform('__zero_access_status', first.args)).toHaveProperty('ast');
                execFileSync('php', ['tests/integration/app.php', 'restore-related'], { env, stdio: 'pipe' });
                psql(`UPDATE tasks SET title='Initial' WHERE id='${alice}'`);
                await until(() => result[0]?.title === 'Initial', 'Unrelated fixture did not restore');

                // Two users observe the same changed resource alongside their
                // own unaffected resources. Only the changed decision disappears,
                // including Bob's denied decision becoming a grant.
                const other = new Zero<typeof schema, undefined, Context>({
                    schema,
                    userID: 'bob',
                    auth: 'bob',
                    context: { user_id: 'bob' },
                    cacheURL: `http://127.0.0.1:${zeroPort}`,
                    kvStore: 'mem',
                    logLevel: 'error',
                });
                const scopedStart = watches.length;
                try {
                    const aliceScoped = status([alice, third]);
                    const bobScoped = status([alice, bob], randomUUID(), other);
                    await until(
                        () =>
                            aliceScoped.complete &&
                            aliceScoped.rows.length === 2 &&
                            bobScoped.complete &&
                            bobScoped.rows.length === 2,
                        'Scoped access decisions did not synchronize',
                    );
                    const aliceChanged = aliceScoped.rows.find((row) => row.binding === binding)!;
                    const bobChanged = bobScoped.rows.find((row) => row.binding === binding)!;
                    const aliceUnchanged = aliceScoped.rows.filter((row) => row.id !== aliceChanged.id);
                    const bobUnchanged = bobScoped.rows.filter((row) => row.id !== bobChanged.id);
                    expect(aliceChanged.state).toBe('allowed');
                    expect(bobChanged.state).toBe('denied');
                    expect(aliceUnchanged[0]?.state).toBe('allowed');
                    expect(bobUnchanged[0]?.state).toBe('allowed');
                    const scopedWireStart = frames.length;
                    const scopedCallbacks = readFileSync(join(root, 'query-callbacks.log'), 'utf8');
                    const scopedChecks = readFileSync(join(root, 'access-actors.log'), 'utf8');
                    psql(`UPDATE tasks SET user_id='bob' WHERE id='${alice}'`);
                    await until(
                        () => aliceScoped.rows.length === 1 && bobScoped.rows.length === 1 && result.length === 1,
                        'Ownership transfer did not invalidate both affected decisions',
                    );
                    psql(`UPDATE tasks SET title='Scoped WAL barrier' WHERE id='${third}'`);
                    await until(
                        () => result[0]?.title === 'Scoped WAL barrier',
                        'Scoped WAL barrier did not replicate',
                    );
                    expect(aliceScoped.rows).toEqual(aliceUnchanged);
                    expect(bobScoped.rows).toEqual(bobUnchanged);
                    expect(readFileSync(join(root, 'query-callbacks.log'), 'utf8')).toBe(scopedCallbacks);
                    expect(readFileSync(join(root, 'access-actors.log'), 'utf8')).toBe(scopedChecks);
                    const scopedWire = Buffer.concat(frames.slice(scopedWireStart)).toString('utf8');
                    expect(scopedWire).toContain(aliceChanged.id);
                    expect(scopedWire).toContain(bobChanged.id);
                    for (const row of [...aliceUnchanged, ...bobUnchanged]) expect(scopedWire).not.toContain(row.id);
                    const aliceRecheck = status();
                    const bobRecheck = status([alice], randomUUID(), other);
                    await until(
                        () => aliceRecheck.rows[0]?.state === 'denied' && bobRecheck.rows[0]?.state === 'allowed',
                        'Targeted rechecks did not reflect transferred ownership',
                    );
                    expect(aliceRecheck.rows[0]?.status).toBe(404);
                } finally {
                    watches.splice(scopedStart).forEach((destroy) => destroy());
                    await other.close();
                    psql(
                        `UPDATE tasks SET user_id='alice' WHERE id='${alice}'; UPDATE tasks SET title='Zebra' WHERE id='${third}'`,
                    );
                    await until(
                        () => result.length === 2 && result[1]?.title === 'Zebra',
                        'Scoped fixtures did not restore',
                    );
                }
                const overlap = status();
                await until(
                    () => overlap.complete && overlap.rows[0]?.state === 'allowed',
                    'New overlapping generation did not authorize',
                );
                expect(first.rows).toEqual(initialStatus);
                const forged = { ...overlap.args, owner: 'alice' };
                expect(await transform('__zero_access_status', forged, 'bob')).toHaveProperty('error', 'parse');
                const bobAST = JSON.stringify((await transform('__zero_access_status', overlap.args, 'bob')).ast);
                expect(bobAST).toContain('permission_access_results');
                expect(bobAST).not.toContain(overlap.rows[0]!.id);
                expect(
                    psql(
                        `SELECT count(DISTINCT owner) FROM permission_access_checks WHERE generation='${overlap.args.generation}'`,
                    ),
                ).toBe('2');
                const changed = { ...overlap.args, bindings: [{ binding, query: guardedName, args: { task: bob } }] };
                expect(await transform('__zero_access_status', changed)).toHaveProperty('error', 'parse');
                expect(
                    await transform('__zero_access_status', {
                        ...overlap.args,
                        bindings: [{ binding, query: queryName, args: {} }],
                    }),
                ).toHaveProperty('error', 'parse');
                expect(await transform(guardedName, { task: alice, __generation: 'invalid' })).toHaveProperty(
                    'error',
                    'parse',
                );

                execFileSync('php', ['tests/integration/app.php', 'revoke'], { env, stdio: 'pipe' });
                const denied = status();
                await until(
                    () => denied.complete && denied.rows[0]?.state === 'denied',
                    'Initial denial never arrived',
                );
                expect(denied.rows[0]?.status).toBe(404);
                execFileSync('php', ['tests/integration/app.php', 'restore'], { env, stdio: 'pipe' });
                await until(
                    () => denied.rows.length === 0 && result.length === 2,
                    'Regrant did not invalidate old denial',
                );
                const regranted = status();
                await until(
                    () => regranted.complete && regranted.rows[0]?.state === 'allowed',
                    'Fresh generation did not recover initial denial',
                );

                // Arbitrary route middleware does not necessarily write permission
                // projections. Only a fresh generation may supersede that denial.
                writeFileSync(join(root, 'access-denied'), 'deny');
                const middlewareDenied = status();
                await until(
                    () => middlewareDenied.complete && middlewareDenied.rows[0]?.state === 'denied',
                    'Backend middleware denial was ignored',
                );
                expect(middlewareDenied.rows[0]?.status).toBe(403);
                expect(result).toHaveLength(2);
                expect(await transform(guardedName, { task: alice, __generation: randomUUID() })).toMatchObject({
                    error: 'app',
                    details: { status: 403 },
                });
                unlinkSync(join(root, 'access-denied'));
                const recoveredAccess = status();
                await until(
                    () => recoveredAccess.complete && recoveredAccess.rows[0]?.state === 'allowed',
                    'Backend middleware recovery did not allow a fresh generation',
                );
                expect(middlewareDenied.rows[0]?.state).toBe('denied');
                expect(await transform(guardedName, { task: alice, __generation: randomUUID() })).toHaveProperty('ast');

                psql("UPDATE users SET access_blocked=true WHERE id='alice'");
                const freshActor = status();
                await until(
                    () => freshActor.complete && freshActor.rows[0]?.state === 'denied',
                    'Access check trusted stale request actor attributes',
                );
                const actorLog = readFileSync(join(root, 'access-actors.log'), 'utf8')
                    .trim()
                    .split('\n')
                    .map((line) => JSON.parse(line));
                expect(actorLog).toContainEqual({ request: true, auth: true, transaction: 1, locked: true });
                psql("UPDATE users SET access_blocked=false WHERE id='alice'");

                const idle = status();
                await until(() => idle.complete && idle.rows[0]?.state === 'allowed', 'Idle status never completed');
                await sleep(300);
                const callbackCount = readFileSync(join(root, 'query-callbacks.log'), 'utf8');
                const actorCount = readFileSync(join(root, 'access-actors.log'), 'utf8');
                await sleep(1200);
                expect(readFileSync(join(root, 'query-callbacks.log'), 'utf8')).toBe(callbackCount);
                expect(readFileSync(join(root, 'access-actors.log'), 'utf8')).toBe(actorCount);

                // Wire audit: Zero v53 sends UTF-8 row-patch chunks prefixed by
                // binary type byte 0. Inspect captured bytes, NOT materialized rows.
                const privateValues = psql(
                    'SELECT owner FROM permission_access_checks UNION SELECT fingerprint FROM permission_access_checks UNION SELECT id FROM permission_subjects',
                ).split('\n');
                const privateTables = psql(
                    `SELECT tablename FROM pg_publication_tables WHERE pubname='${publication}' AND tablename NOT IN ('tasks','lists','tags','comments','task_tag','permission_access_revision','permission_access_results')`,
                ).split('\n');
                const forbidden = [
                    ...privateTables,
                    '"owner"',
                    '"fingerprint"',
                    '"expires_at"',
                    '"access_blocked"',
                    '"subject_key"',
                    '"global_key"',
                    '"rule_id"',
                    '"domain_key"',
                    '"secret"',
                    ...privateValues,
                ];
                const leaks = (captured: readonly Buffer[]) => {
                    const wire = Buffer.concat(captured.map((frame) => frame.subarray(1))).toString('utf8');
                    return forbidden.filter((value) => wire.includes(value));
                };
                expect(frames.length).toBeGreaterThan(0);
                expect(frames.every((frame) => frame[0] === 0)).toBe(true);
                expect(Buffer.concat(frames).toString('utf8')).toContain('permission_access_results');
                expect(Buffer.concat(frames).toString('utf8')).not.toContain('permission_access_revision');
                expect(Buffer.concat(frames).toString('utf8')).toContain('Initial'); // positive control
                expect(leaks(frames)).toEqual([]);
                // Deliberately put a real private fingerprint in a PUBLIC title.
                // The same binary observer must flag it, or the audit is vacuous.
                const negativeStart = frames.length;
                const fingerprint = psql('SELECT fingerprint FROM permission_access_checks LIMIT 1');
                expect(fingerprint).toMatch(/^[a-f0-9]{64}$/);
                psql(`UPDATE tasks SET title='${fingerprint}' WHERE id='${alice}'`);
                await until(
                    () => result.some((row) => row.title === fingerprint),
                    'Unsafe wire control did not replicate',
                );
                expect(leaks(frames.slice(negativeStart))).toContain(fingerprint);
                psql(`UPDATE tasks SET title='Initial' WHERE id='${alice}'`);
                await until(() => result[0]?.title === 'Initial', 'Unsafe control cleanup did not replicate');
                const negativeEnd = frames.length;
                auditWire = () => {
                    const currentPrivateValues = psql(
                        'SELECT owner FROM permission_access_checks UNION SELECT fingerprint FROM permission_access_checks',
                    ).split('\n');
                    const captured = [...frames.slice(0, negativeStart), ...frames.slice(negativeEnd)];
                    expect(leaks(captured)).toEqual([]);
                    const wire = Buffer.concat(captured.map((frame) => frame.subarray(1))).toString('utf8');
                    expect(currentPrivateValues.filter((value) => wire.includes(value))).toEqual([]);
                };

                // The real Inertia adapter consumes real Laravel page metadata.
                // Its private component must wait for BOTH status and business
                // completion, unmount before revocation callbacks, and remount
                // with a new business query identity after regrant/reconnect.
                const pageResponse = await fetch(`${base}/fixture/tasks/${alice}`, {
                    headers: { Authorization: 'Bearer alice', 'X-Inertia': 'true', Accept: 'text/html' },
                });
                expect(pageResponse.status).toBe(200);
                const page = (await pageResponse.json()) as { component: string; props: Record<string, unknown> };
                expect(page.props).not.toHaveProperty('task');
                let mounts = 0;
                let unmounts = 0;
                const losses: { status: number; privateMounted: boolean }[] = [];
                let protectedHost: ReturnType<typeof render> | undefined;
                function PrivatePage(props: Record<string, unknown>) {
                    const task = props.task as Data<{ id: string; title: string } | undefined>;
                    expect(task.complete).toBe(true);
                    expect(task.connection.name).toBe('connected');
                    expect(task.data?.id).toBe(alice);
                    useEffect(() => {
                        mounts++;
                        return () => {
                            unmounts++;
                        };
                    }, []);
                    return <div data-private>{task.data?.title}</div>;
                }
                const adapter = createInertiaAdapter(module.exports.bindings, {
                    access: {
                        onRevoked: ({ status: code }) =>
                            losses.push({
                                status: code,
                                privateMounted: !!protectedHost?.container.querySelector('[data-private]'),
                            }),
                    },
                });
                const ProtectedPage = adapter.wrap(PrivatePage, page);
                const uiLogStart = readFileSync(join(root, 'query-callbacks.log'), 'utf8').length;
                const generations = () =>
                    new Set(
                        readFileSync(join(root, 'query-callbacks.log'), 'utf8')
                            .slice(uiLogStart)
                            .trim()
                            .split('\n')
                            .filter(Boolean)
                            .flatMap(
                                (line) =>
                                    (
                                        JSON.parse(line) as [
                                            string,
                                            { name: string; args: { __generation?: string }[] }[],
                                        ]
                                    )[1],
                            )
                            .filter((query) => query.name === guardedName)
                            .map((query) => query.args[0]?.['__generation'])
                            .filter((value) => value !== undefined),
                    );
                const browserFetch = vi.spyOn(globalThis, 'fetch');
                protectedHost = render(
                    <ZeroProvider zero={zero}>
                        <ProtectedPage {...page.props} />
                    </ZeroProvider>,
                );
                try {
                    expect(protectedHost.container.querySelector('[data-private]')).toBeNull();
                    await waitFor(() => expect(mounts).toBe(1), { timeout: 10000 });
                    expect(generations().size).toBe(1);
                    await sleep(300);
                    const uiCallbacks = readFileSync(join(root, 'query-callbacks.log'), 'utf8');
                    const uiChecks = readFileSync(join(root, 'access-actors.log'), 'utf8');
                    execFileSync('php', ['tests/integration/app.php', 'revoke-related'], { env, stdio: 'pipe' });
                    psql(`UPDATE tasks SET title='UI isolation barrier' WHERE id='${alice}'`);
                    await waitFor(() => expect(protectedHost.container.textContent).toBe('UI isolation barrier'), {
                        timeout: 10000,
                    });
                    expect(readFileSync(join(root, 'query-callbacks.log'), 'utf8')).toBe(uiCallbacks);
                    expect(readFileSync(join(root, 'access-actors.log'), 'utf8')).toBe(uiChecks);
                    expect(mounts).toBe(1);
                    expect(unmounts).toBe(0);
                    expect(generations().size).toBe(1);
                    expect(losses).toEqual([]);
                    execFileSync('php', ['tests/integration/app.php', 'restore-related'], { env, stdio: 'pipe' });
                    // Exercise the actual 15-second renewal on the socket, not
                    // just fake timers. Approval renewal must not remount private
                    // content or churn its business query generation.
                    await until(
                        () =>
                            readFileSync(join(root, 'query-callbacks.log'), 'utf8')
                                .slice(uiCallbacks.length)
                                .includes('"name":"__zero_access_status"'),
                        'Scheduled native status renewal never reached Laravel',
                    );
                    await until(
                        () => readFileSync(join(root, 'access-actors.log'), 'utf8').length > uiChecks.length,
                        'Scheduled renewal did not replay the route guard',
                    );
                    await sleep(5500); // The initial 20-second lease has expired.
                    expect(protectedHost.container.querySelector('[data-private]')).not.toBeNull();
                    expect(mounts).toBe(1);
                    expect(unmounts).toBe(0);
                    expect(generations().size).toBe(1);
                    execFileSync('php', ['tests/integration/app.php', 'revoke'], { env, stdio: 'pipe' });
                    await waitFor(() => expect(losses).toEqual([{ status: 404, privateMounted: false }]), {
                        timeout: 10000,
                    });
                    expect(unmounts).toBe(1);
                    expect(protectedHost.container.querySelector('[data-private]')).toBeNull();
                    execFileSync('php', ['tests/integration/app.php', 'restore'], { env, stdio: 'pipe' });
                    await waitFor(() => expect(mounts).toBe(2), { timeout: 10000 });
                    expect(generations().size).toBe(2);
                    // Close the actual socket with a new arbitrary backend deny.
                    // Retained complete rows must not remount during reconnect.
                    writeFileSync(join(root, 'access-denied'), 'deny');
                    await act(async () => {
                        const socket = sockets.filter((candidate) => candidate.readyState === WebSocket.OPEN).at(-1)!;
                        await new Promise<void>((resolve) => {
                            socket.addEventListener('close', () => resolve(), { once: true });
                            socket.close(4000, 'Fixture connection loss');
                        });
                    });
                    await waitFor(() => expect(protectedHost.container.querySelector('[data-private]')).toBeNull(), {
                        timeout: 10000,
                    });
                    await waitFor(
                        () => expect(protectedHost.container.querySelector('[data-zero-access="403"]')).not.toBeNull(),
                        { timeout: 10000 },
                    );
                    expect(mounts).toBe(2);
                    expect(unmounts).toBe(2);
                    unlinkSync(join(root, 'access-denied'));
                    // Visibility renewal requests a fresh native status generation,
                    // without an access fetch/EventSource or permission write.
                    await act(async () => {
                        document.dispatchEvent(new Event('visibilitychange'));
                    });
                    await waitFor(() => expect(mounts).toBe(3), { timeout: 10000 });
                    expect(generations().size).toBe(3);
                    expect(browserFetch).not.toHaveBeenCalled();
                } finally {
                    protectedHost.unmount();
                    adapter.dispose();
                    browserFetch.mockRestore();
                }
                auditWire();

                let activeReset = '';
                try {
                    execFileSync('php', ['tests/integration/app.php', 'reset'], { env, encoding: 'utf8' });
                } catch (error) {
                    if (error && typeof error === 'object' && 'stdout' in error) activeReset = String(error.stdout);
                    else throw error;
                }
                expect(activeReset).toContain('replication slot is active');
                expect(result[0]?.id).toBe(alice);
                expect(result[0]).not.toHaveProperty('secret');
                psql(`UPDATE tasks SET title='Updated upstream' WHERE id='${alice}'`);
                await until(
                    () => result[0]?.title === 'Updated upstream',
                    'Upstream update did not reach subscription',
                );
                let state: Data<readonly { id: string; title: string }[]> | undefined;
                let renders = 0;
                function Probe({ direction }: { direction: 'asc' | 'desc' }) {
                    state = useData(tasks({ direction }));
                    renders++;
                    return null;
                }
                const tree = (direction: 'asc' | 'desc') => (
                    <ZeroProvider zero={zero!}>
                        <Probe direction={direction} />
                    </ZeroProvider>
                );
                const host = render(tree('asc'));
                try {
                    await waitFor(() => expect(state?.status).toBe('success'), { timeout: 10000 });
                    expect(state?.data.map((row) => row.id)).toEqual([alice, third]);
                    host.rerender(tree('desc'));
                    expect(state?.status).toBe('refreshing');
                    expect(state?.data.map((row) => row.id)).toEqual([third, alice]);
                    const liveEdit = zero.mutate(update.prepare({ title: 'A live edit' }, { task: alice }));
                    await liveEdit.client;
                    await waitFor(() => expect(state?.status).toBe('success'), { timeout: 10000 });
                    expect(state?.data.map((row) => row.id)).toEqual([third, alice]);
                    expect(state?.data[1]?.title).toBe('A live edit');
                    expect((await liveEdit.server).type).toBe('success');
                } finally {
                    host.unmount();
                }
                const unmounted = renders;
                psql(`UPDATE tasks SET title='After unmount' WHERE id='${alice}'`);
                await sleep(200);
                expect(renders).toBe(unmounted);
                const rejected = zero.mutate(update.prepare({ title: 'Forbidden' }, { task: bob }));
                expect((await rejected.server).type).toBe('error');
                expect(psql(`SELECT title FROM tasks WHERE id='${bob}'`)).toBe('Other owner');
                psql(`UPDATE tasks SET title='Locked' WHERE id='${alice}'`);
                await until(() => result[0]?.title === 'Locked', 'Policy fixture did not synchronize');
                const optimistic = zero.mutate(update.prepare({ title: 'Optimistic edit' }, { task: alice }));
                expect((await optimistic.client).type).toBe('success');
                expect((await optimistic.server).type).toBe('error');
                await until(() => result[0]?.title === 'Locked', 'Rejected optimistic edit did not roll back');
                const request = store.prepare({ title: 'Recovered result' });
                const recovered = zero.mutate(request);
                expect(await recovered.client).toEqual({ type: 'success', data: { id: request.args.prepared.ids.s0 } });
                expect(await recovered.server).toEqual({ type: 'success', data: { id: request.args.prepared.ids.s0 } });
                await until(
                    () => existsSync(join(root, 'lost-response.json')),
                    'The successful callback response was not discarded',
                );
                expect(
                    JSON.parse(readFileSync(join(root, 'lost-response.json'), 'utf8')).mutations[0].result.data,
                ).toEqual({ id: request.args.prepared.ids.s0 });
                expect(psql("SELECT count(*) FROM tasks WHERE title='Recovered result' AND user_id='alice'")).toBe('1');
                // Exercise the real managed Casbin writer, PostgreSQL projector,
                // generated PHP compiler and unchanged native subscription together.
                execFileSync('php', ['tests/integration/app.php', 'revoke'], { env, stdio: 'pipe' });
                await until(() => result.length === 0, 'Managed permission revocation did not remove live rows');
                psql(`UPDATE tasks SET title='Written after grant revocation' WHERE id='${alice}'`);
                await sleep(300);
                expect(result).toEqual([]);
                execFileSync('php', ['tests/integration/app.php', 'restore'], { env, stdio: 'pipe' });
                await until(
                    () => result.some((row) => row.title === 'Written after grant revocation'),
                    'Restored managed grant did not restore the unchanged subscription',
                );
            } finally {
                watches.forEach((destroy) => destroy());
                unsubscribe();
                view.destroy();
            }
            await zero.close();
            zero = new Zero<typeof schema, undefined, Context>({
                schema,
                mutators: allMutators,
                userID: 'bob',
                auth: 'bob',
                context: { user_id: 'bob' },
                cacheURL: `http://127.0.0.1:${zeroPort}`,
                kvStore: 'mem',
                logLevel: 'error',
            });
            const bobView = zero.materialize(tasks({ direction: 'asc' }).request());
            let bobIDs: readonly string[] = [];
            const removeBob = bobView.addListener((rows) => {
                bobIDs = rows.map((row) => row.id);
            });
            // Guessing Alice's exact generation and binding is not ownership.
            const bobAccess = zero.materialize(
                access['__zero_access_status']({
                    generation,
                    bindings: [{ binding, query: guardedName, args: { task: alice } }],
                }),
                { ttl: 0 },
            );
            let bobStatus: { id: string; state: string; status: number | null } | undefined;
            const removeBobAccess = bobAccess.addListener((rows, type) => {
                if (type === 'complete') bobStatus = rows[0];
            });
            try {
                await until(() => bobIDs.length === 1, 'New identity did not synchronize');
                expect(bobIDs).toEqual([bob]);
                await until(() => bobStatus !== undefined, 'Other actor reserved status did not synchronize');
                expect(bobStatus).toMatchObject({ state: 'denied', status: 404 });
                expect(bobStatus?.id).not.toBe(aliceStatusID);
            } finally {
                removeBobAccess();
                bobAccess.destroy();
                removeBob();
                bobView.destroy();
            }
            await zero.close();
            zero = new Zero<typeof schema, undefined, Context>({
                schema,
                mutators: allMutators,
                userID: 'bob',
                auth: 'bob',
                context: { user_id: 'alice' },
                cacheURL: `http://127.0.0.1:${zeroPort}`,
                kvStore: 'mem',
                logLevel: 'error',
            });
            const forgedContext = store.prepare({ title: 'Trusted owner' });
            const authoritative = zero.mutate(forgedContext);
            expect((await authoritative.client).type).toBe('success');
            expect(
                await zero.run(createBuilder(schema).tasks.where('id', forgedContext.args.prepared.ids.s0).one()),
            ).toMatchObject({ title: 'Trusted owner', user_id: 'alice' });
            expect((await authoritative.server).type).toBe('success');
            expect(psql(`SELECT user_id FROM tasks WHERE id='${forgedContext.args.prepared.ids.s0}'`)).toBe('bob');
            auditWire();
            await zero.close();
            zero = undefined;
            await stop(cache);
            expect(cache.exitCode).not.toBeNull();
            const reset = execFileSync('php', ['tests/integration/app.php', 'reset', 'force'], {
                env,
                encoding: 'utf8',
            });
            expect(reset).toContain('Local replica files reset');
            expect(existsSync(join(root, 'replica.db'))).toBe(false);
            expect(psql('SELECT count(*) FROM tasks')).toBe('5');
            cache = spawn('php', ['tests/integration/app.php', 'dev'], {
                env,
                detached: true,
                stdio: ['ignore', 'pipe', 'pipe'],
            });
            cache.stderr?.on('data', (chunk) => {
                logs += String(chunk);
            });
            cache.stdout?.on('data', (chunk) => {
                logs += String(chunk);
            });
            await until(async () => {
                try {
                    return (await fetch(`http://127.0.0.1:${zeroPort}/`)).ok;
                } catch {
                    return false;
                }
            }, 'Cache did not rebuild after local file reset');
        } catch (error) {
            await sleep(100);
            for (const name of ['laravel.log', 'php.log']) {
                if (existsSync(join(root, name))) logs += readFileSync(join(root, name), 'utf8');
            }
            const safe = logs.replaceAll(key, '[redacted]').replace(/postgres(?:ql)?:\/\/\S+/g, '[database redacted]');
            throw new Error(`${String(error)}\n${safe.slice(-10000)}`, { cause: error });
        } finally {
            await zero?.close();
            zero = undefined;
            globalThis.WebSocket = WebSocket;
            await stop(cache);
            await stop(php);
            if (created) {
                // Exact disposable database's slots only; this never selects a developer app.
                await until(
                    () =>
                        psql(
                            `SELECT count(*) FROM pg_replication_slots WHERE database='${database}' AND active`,
                            'postgres',
                        ) === '0',
                    'Disposable database replication did not stop',
                );
                psql(
                    `SELECT pg_drop_replication_slot(slot_name) FROM pg_replication_slots WHERE database='${database}' AND NOT active`,
                    'postgres',
                );
                psql(`DROP DATABASE "${database}" WITH (FORCE)`, 'postgres');
            }
            rmSync(root, { recursive: true, force: true });
        }
    },
    120000,
);
