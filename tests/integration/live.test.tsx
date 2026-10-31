// @vitest-environment jsdom
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, readFileSync, existsSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { randomBytes } from 'node:crypto';
import { Zero, createBuilder, defineQueriesWithType, defineQueryWithType } from '@rocicorp/zero';
import { runInNewContext } from 'node:vm';
import { z } from 'zod';
import {
    createControllerMutations,
    type ControllerMutationContract,
} from '../../packages/client/src/controller-mutation';
import { ZeroProvider } from '@rocicorp/zero/react';
import { render, waitFor } from '@testing-library/react';
import { useData, type Data } from '../../packages/client/src/react/data';
import { liveQuery, withQueryInputs } from '../../packages/client/src/live-query';
import type { index } from '../interop/controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { expect, it } from 'vite-plus/test';
import { schema, definitions } from '../interop/generated';
import type { ClientContext } from '../../packages/client/src/contract';

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
        let zero: Zero<typeof schema, undefined, ClientContext> | undefined;
        let logs = '';
        let created = false;
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
            const contracts = JSON.parse(readFileSync(join(root, 'controller-contracts.json'), 'utf8')) as Record<
                string,
                ControllerMutationContract
            >;
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
            const controllers = createControllerMutations(schema, definitions, contracts, {
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
                '@rocicorp/zero': { createBuilder, defineQueriesWithType, defineQueryWithType },
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
                },
            };
            runInNewContext(compiled, { require: (specifier: string) => modules[specifier], module });
            const tasks = module.exports.actions['ZeroForLaravel\\Tests\\Fixtures\\QueryController@sorted.tasks']!;
            const queryName = tasks({ direction: 'asc' }).request().query.queryName;
            expect(
                psql(
                    `SELECT attnames::text FROM pg_publication_tables WHERE pubname='${publication}' AND tablename='tasks'`,
                ),
            ).not.toContain('secret');
            expect(psql(`SELECT count(*) FROM pg_publication_tables WHERE pubname='${publication}'`)).toBe('5');
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
            zero = new Zero<typeof schema, undefined, ClientContext>({
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
            try {
                await until(() => complete && result.length === 2, 'Authorized query never completed');
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
            } finally {
                unsubscribe();
                view.destroy();
            }
            await zero.close();
            zero = new Zero<typeof schema, undefined, ClientContext>({
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
            try {
                await until(() => bobIDs.length === 1, 'New identity did not synchronize');
                expect(bobIDs).toEqual([bob]);
            } finally {
                removeBob();
                bobView.destroy();
            }
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
            expect(psql('SELECT count(*) FROM tasks')).toBe('4');
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
    90000,
);
