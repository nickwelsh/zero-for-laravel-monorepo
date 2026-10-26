// @vitest-environment jsdom
import { spawn, execFileSync, type ChildProcess } from 'node:child_process';
import { mkdtempSync, mkdirSync, rmSync, readFileSync, existsSync, symlinkSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { randomBytes } from 'node:crypto';
import { Zero } from '@rocicorp/zero';
import { ZeroProvider } from '@rocicorp/zero/react';
import { render, waitFor } from '@testing-library/react';
import { useData, type Data } from '../../packages/client/src/react/data';
import type { SelectionData } from '../../packages/client/src/query';
import { expect, it } from 'vite-plus/test';
import { Task, schema, mutators } from '../interop/generated';
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
    if (!child || child.exitCode !== null) return;
    child.kill('SIGTERM');
    await Promise.race([new Promise<void>((resolve) => child.once('exit', () => resolve())), sleep(5000)]);
    if (child.exitCode === null) child.kill('SIGKILL');
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
                    Array.from({ length: 257 }, (_, i) => ({ id: String(i), name: 'Task.all', args: [{}] })),
                ]),
            });
            const transformed = (await batch.json()) as { queries: unknown[] };
            expect(transformed.queries).toHaveLength(257);
            symlinkSync(join(process.cwd(), 'node_modules'), join(root, 'node_modules'), 'dir');
            cache = spawn('php', ['tests/integration/app.php', 'dev'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
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
                mutators,
                userID: 'alice',
                auth: 'alice',
                context: { user_id: 'alice' },
                cacheURL: `http://127.0.0.1:${zeroPort}`,
                kvStore: 'mem',
                logLevel: 'error',
            });
            const view = zero.materialize(Task.query.all().request());
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
                const selection = Task.query.all();
                let state: Data<SelectionData<typeof selection>> | undefined;
                let renders = 0;
                function Probe({ direction }: { direction: 'asc' | 'desc' }) {
                    state = useData(Task.query.all().reorder([['title', direction]]));
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
                    expect(state?.data.map((row) => row.data.id)).toEqual([alice, third]);
                    host.rerender(tree('desc'));
                    expect(state?.status).toBe('refreshing');
                    expect(state?.data.map((row) => row.data.id)).toEqual([alice, third]);
                    const liveEdit = zero.mutate(Task.update().prepare({ id: alice, title: 'A live edit' }));
                    await liveEdit.client;
                    await waitFor(() => expect(state?.data[0]?.data.title).toBe('A live edit'), { timeout: 10000 });
                    await waitFor(() => expect(state?.status).toBe('success'), { timeout: 10000 });
                    expect(state?.data.map((row) => row.data.id)).toEqual([third, alice]);
                    expect((await liveEdit.server).type).toBe('success');
                } finally {
                    host.unmount();
                }
                const unmounted = renders;
                psql(`UPDATE tasks SET title='After unmount' WHERE id='${alice}'`);
                await sleep(200);
                expect(renders).toBe(unmounted);
                const rejected = zero.mutate(Task.update().prepare({ id: bob, title: 'Forbidden' }));
                expect((await rejected.server).type).toBe('error');
                expect(psql(`SELECT title FROM tasks WHERE id='${bob}'`)).toBe('Other owner');
                psql(`UPDATE tasks SET title='Locked' WHERE id='${alice}'`);
                await until(() => result[0]?.title === 'Locked', 'Policy fixture did not synchronize');
                const optimistic = zero.mutate(Task.update().prepare({ id: alice, title: 'Optimistic edit' }));
                expect((await optimistic.client).type).toBe('success');
                expect((await optimistic.server).type).toBe('error');
                await until(() => result[0]?.title === 'Locked', 'Rejected optimistic edit did not roll back');
            } finally {
                unsubscribe();
                view.destroy();
            }
            await zero.close();
            zero = new Zero<typeof schema, undefined, ClientContext>({
                schema,
                mutators,
                userID: 'bob',
                auth: 'bob',
                context: { user_id: 'bob' },
                cacheURL: `http://127.0.0.1:${zeroPort}`,
                kvStore: 'mem',
                logLevel: 'error',
            });
            const bobView = zero.materialize(Task.query.all().request());
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
            expect(psql('SELECT count(*) FROM tasks')).toBe('3');
            cache = spawn('php', ['tests/integration/app.php', 'dev'], { env, stdio: ['ignore', 'pipe', 'pipe'] });
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
