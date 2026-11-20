// Run from the repository root. See README.md for prerequisites.
import assert from 'node:assert/strict';
import { execFileSync, spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import {
    closeSync,
    copyFileSync,
    existsSync,
    mkdirSync,
    mkdtempSync,
    openSync,
    readFileSync,
    rmSync,
    symlinkSync,
    writeFileSync,
} from 'node:fs';
import { createServer } from 'node:net';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';

async function port() {
    const server = createServer();
    await new Promise((done) => server.listen(0, '127.0.0.1', done));
    const { port: assigned } = server.address();
    await new Promise((done) => server.close(done));
    return assigned;
}
async function until(check, message) {
    for (let i = 0; i < 600; i++) {
        if (await check()) return;
        await delay(100);
    }
    throw new Error(message);
}
const suffix = randomBytes(6).toString('hex');
const db = `laravel_zero_test_todos_${suffix}`;
const root = mkdtempSync(join(tmpdir(), 'zero-todos-'));
const fixture = import.meta.dirname;
const app = join(fixture, 'app.php');
const base = `http://127.0.0.1:${await port()}`;
const env = {
    ...process.env,
    PGHOST: process.env.ZERO_TEST_PG_HOST ?? '127.0.0.1',
    PGPORT: process.env.ZERO_TEST_PG_PORT ?? '55433',
    PGUSER: process.env.ZERO_TEST_PG_USER ?? 'postgres',
    PGPASSWORD: process.env.ZERO_TEST_PG_PASSWORD ?? '',
    ZERO_INTEGRATION_ROOT: root,
    ZERO_LIVE_DATABASE: db,
    APP_URL: base,
    APP_ENV: 'local',
    APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    ZERO_API_KEY: randomBytes(32).toString('hex'),
    ZERO_APP_ID: `test_${suffix}`,
    ZERO_APP_PUBLICATIONS: `laravel_zero_test_${suffix}`,
    ZERO_PORT: String(await port()),
    ZERO_CHANGE_STREAMER_PORT: String(await port()),
    ZERO_LITESTREAM_PORT: String(await port()),
    AGENT_BROWSER_SESSION: `todo-${suffix}`,
    AGENT_BROWSER_RESTORE_SAVE: 'never',
    ACTION_VIDEO_SESSION: `todo-${suffix}`,
    PHP_CLI_SERVER_WORKERS: '3',
};
const artifacts = process.env.TODO_EVIDENCE && resolve(process.env.TODO_EVIDENCE);
const recorder = process.env.TODO_ACTION_VIDEO;
const run = (bin, args) => execFileSync(bin, args, { env, encoding: 'utf8', stdio: 'pipe' }).trim();
const sql = (statement, database = db) =>
    run('psql', ['-X', '-d', database, '-v', 'ON_ERROR_STOP=1', '-Atc', statement]);
const ab = (...args) => run('agent-browser', ['--session', env.AGENT_BROWSER_SESSION, '--restore', ...args]);
let recording = false;
const input = (...args) => (recording ? run('node', [recorder, ...args]) : ab(...args));
const text = (name) => (existsSync(join(root, name)) ? readFileSync(join(root, name), 'utf8') : '');
const write = (name, value) => writeFileSync(join(root, name), value);
const remove = (name) => rmSync(join(root, name), { force: true });
const screenshots = (name) => artifacts && ab('screenshot', join(artifacts, `${name}.png`));
const filtered = `${base}/todos?status=open&sort=title`;
const checkURL = (url) => assert.equal(ab('get', 'url'), url);
const count = (title) => sql(`SELECT count(*) FROM todos WHERE title='${title}'`);
const hold = () => {
    remove('arrived');
    write('hold', '1');
};
const arrived = () => until(() => existsSync(join(root, 'arrived')), 'Missing native mutation callback');
const release = () => remove('hold');
const submit = () => input('click', 'form button');
const fill = (title) => input('fill', 'input[name=title]', title);
const wait = (selector) => ab('wait', selector);
const facts = [];
function fact(claim, observations) {
    facts.push({ claim, ...observations });
    console.log(claim, JSON.stringify(observations));
}
const workers = [];
function start(args) {
    const fd = openSync(join(root, 'services.log'), 'a');
    workers.push(spawn('php', args, { detached: true, env, stdio: ['ignore', fd, fd] }));
    closeSync(fd);
}
async function stop(child) {
    if (child.exitCode !== null || child.signalCode !== null) return;
    // zero-cache workers can own separate process groups (see live.test.tsx).
    const tree = run('ps', ['-eo', 'pid=,ppid='])
        .split('\n')
        .map((s) => s.trim().split(/\s+/).map(Number));
    const owned = new Set([child.pid]);
    for (let size = 0; size !== owned.size;) {
        size = owned.size;
        for (const [pid, parent] of tree) if (owned.has(parent)) owned.add(pid);
    }
    process.kill(-child.pid, 'SIGTERM');
    await Promise.race([new Promise((done) => child.once('exit', done)), delay(3000)]);
    for (const pid of owned) {
        try {
            process.kill(pid, 'SIGKILL');
        } catch (error) {
            if (error.code !== 'ESRCH') throw error;
        }
    }
}
let created = false;
let launched = false;
try {
    sql(`CREATE DATABASE "${db}"`, 'postgres');
    created = true;
    for (const path of [
        'bootstrap/cache',
        'storage/framework/views',
        'storage/framework/cache',
        'storage/framework/sessions',
        'storage/logs',
        'resources/views',
    ])
        mkdirSync(join(root, path), { recursive: true });
    symlinkSync(resolve('node_modules'), join(root, 'node_modules'), 'dir');
    copyFileSync(join(fixture, 'app.blade.php'), join(root, 'resources/views/app.blade.php'));
    copyFileSync(join(fixture, 'app.jsx'), join(root, 'app.jsx'));
    const baseline = readFileSync(join(fixture, 'forms.tsx'), 'utf8');
    const swapped = baseline
        .replace("from '@inertiajs/react'", "from '@nickwelsh/zero-for-laravel/react'")
        .replace(
            "from '../../../examples/todos/resources/js/wayfinder/App/Http/Controllers/TodoController'",
            "from './TodoMigration/TodoController'",
        );
    const lines = baseline.split('\n');
    assert.deepEqual(
        swapped.split('\n').flatMap((line, i) => (line === lines[i] ? [] : [i + 1])),
        [2, 3],
    );
    write('forms.tsx', swapped);
    write('zero.config.ts', `export const options = {cacheURL: 'http://127.0.0.1:${env.ZERO_PORT}'};\n`);
    console.log(run('php', [app]));
    assert.ok(existsSync(join(root, 'generated.react.ts')), 'Laravel failed to generate the fixture');
    write('tsconfig.json', JSON.stringify({ extends: resolve('tsconfig.json'), include: ['forms.tsx'], exclude: [] }));
    run('node_modules/.bin/tsc', ['-p', root]);
    run('node_modules/.bin/esbuild', [
        join(root, 'app.jsx'),
        '--bundle',
        '--jsx=automatic',
        '--target=es2022',
        `--tsconfig=${resolve('tsconfig.json')}`,
        '--define:process.env.NODE_ENV="production"',
        `--outfile=${root}/app.js`,
    ]);
    start(['-S', base.slice(7), app]);
    start([app, 'dev']);
    await until(async () => {
        if (workers.some((w) => w.exitCode !== null)) throw new Error('Fixture service exited');
        try {
            return (await fetch(`http://127.0.0.1:${env.ZERO_PORT}/`)).ok;
        } catch {
            return false;
        }
    }, 'Zero did not start');
    launched = true;
    ab('open', `${base}/fixture/session`);
    ab('set', 'viewport', '960', '640', '2');
    wait('input[name=title]');
    checkURL(filtered);
    if (artifacts) mkdirSync(artifacts, { recursive: true });
    if (recorder && artifacts) {
        run('node', [recorder, 'start', join(root, 'raw.mp4'), '--fps', '15']);
        recording = true;
    }
    const initialRequests = text('requests');
    const initialHistory = Number(ab('eval', 'history.length'));
    fill('Review launch checklist');
    input('click', 'input[type=checkbox]');
    hold();
    submit();
    await arrived();
    wait('[data-zero-access="unknown"]');
    const predicted = ab('get', 'url');
    assert.match(predicted.slice(base.length), /^\/todos\/[\da-f-]{36}$/);
    assert.equal(ab('eval', 'window.todoShowMounts'), '0');
    assert.equal(count('Review launch checklist'), '0');
    assert.equal(Number(ab('eval', 'history.length')), initialHistory + 1);
    fact('Before ACK', { path: predicted.slice(base.length), privateMounts: 0, rows: 0 });
    if (recording) input('hover', '[data-zero-access="unknown"]');
    screenshots('01-before-ack');
    release();
    wait('[data-private]');
    checkURL(predicted);
    assert.equal(Number(ab('eval', 'history.length')), initialHistory + 1);
    assert.equal(ab('get', 'text', 'h1'), 'Review launch checklist');
    assert.equal(sql("SELECT id FROM todos WHERE title='Review launch checklist'"), predicted.split('/').pop());
    assert.doesNotMatch(text('requests').slice(initialRequests.length), /^GET \/todos/m);
    fact('Accepted', {
        predictedAndCommittedID: predicted.split('/').pop(),
        title: ab('get', 'text', 'h1'),
        pageGets: 0,
    });
    screenshots('02-accepted');
    if (recording) {
        input('hover', '[data-private] h1');
        run('node', [recorder, 'stop', join(artifacts, 'create-todo.mp4')]);
        recording = false;
    }
    fill('Reviewed launch checklist');
    submit();
    await until(() => count('Reviewed launch checklist') === '1', 'Bound edit did not commit');
    ab('wait', '--text', 'Reviewed launch checklist');
    checkURL(predicted);
    hold();
    ab('find', 'role', 'button', 'click', '--name', 'Delete todo');
    await arrived();
    ab('wait', '--fn', '!document.querySelector("[data-private]")');
    checkURL(predicted);
    assert.equal(count('Reviewed launch checklist'), '1');
    release();
    wait('input[type=checkbox]');
    checkURL(`${base}/todos`);
    assert.equal(count('Reviewed launch checklist'), '0');
    fact('Direct delete (server-timed)', { beforeACK: predicted.slice(base.length), afterACK: '/todos', rows: 0 });

    ab('find', 'role', 'link', 'click', '--name', 'Todos');
    ab('wait', '--url', filtered);
    wait('input[type=checkbox]');
    fill('Keep the filter');
    hold();
    submit();
    await arrived();
    checkURL(filtered);
    assert.equal(count('Keep the filter'), '0');
    release();
    await until(() => count('Keep the filter') === '1', 'Unchecked create did not commit');
    ab('wait', '--fn', '!document.querySelector("form button").disabled');
    checkURL(filtered);
    fact('Unchecked stay', { url: filtered.slice(base.length), rows: 1 });

    fill('Reserved title');
    ab('click', 'input[type=checkbox]');
    hold();
    submit();
    await arrived();
    wait('[data-zero-access="unknown"]');
    assert.notEqual(ab('get', 'url'), filtered);
    release();
    wait('[role=alert]');
    checkURL(filtered);
    assert.equal(ab('get', 'value', 'input[name=title]'), 'Reserved title');
    assert.equal(ab('is', 'checked', 'input[type=checkbox]'), 'true');
    assert.equal(ab('get', 'text', '[role=alert]'), 'This title is reserved.');
    assert.equal(count('Reserved title'), '0');
    fact('Rejected', {
        url: filtered.slice(base.length),
        draft: 'Reserved title',
        checked: true,
        fieldError: 'This title is reserved.',
        rows: 0,
    });
    screenshots('03-recovered');

    fill('Finish elsewhere');
    hold();
    submit();
    await arrived();
    wait('[data-zero-access="unknown"]');
    ab('find', 'role', 'link', 'click', '--name', 'Notes');
    ab('wait', '--text', 'My notes');
    const notesResults = text('results').length;
    release();
    await until(
        () => text('results').length > notesResults && count('Finish elsewhere') === '1',
        'Departed mutation did not finish',
    );
    // This independent update's WAL position follows the create and its stored
    // mutation result. Seeing it on Notes is a browser-side replication barrier.
    sql("UPDATE todos SET title='Finished on the server' WHERE title='Finish elsewhere'");
    ab('wait', '--text', 'Finished on the server');
    checkURL(`${base}/notes`);
    fact('Departed before acceptance', { url: '/notes', committed: 1 });

    // Leave a second speculative show before Laravel rejects the reserved title.
    // The provider owns the default status: the fixture installs no event handler.
    ab('find', 'role', 'link', 'click', '--name', 'Todos');
    ab('wait', '--url', filtered);
    wait('input[type=checkbox]');
    fill('Reserved title');
    ab('check', 'input[type=checkbox]');
    hold();
    submit();
    await arrived();
    wait('[data-zero-access="unknown"]');
    assert.match(ab('get', 'url').slice(base.length), /^\/todos\/[\da-f-]{36}$/);
    ab('find', 'role', 'link', 'click', '--name', 'Notes');
    ab('wait', '--text', 'My notes');
    ab('eval', 'window.departedNotes = document.querySelector("h1"); true');
    const notice = '[data-zero-mutation-error]';
    assert.equal(ab('get', 'count', notice), '0');
    release();
    wait(notice);
    checkURL(`${base}/notes`);
    assert.equal(
        ab('eval', 'window.departedNotes === document.querySelector("h1") && window.departedNotes.isConnected'),
        'true',
    );
    assert.equal(ab('is', 'visible', notice), 'true');
    assert.equal(ab('get', 'text', notice), 'A previous change could not be saved.');
    assert.equal(count('Reserved title'), '0');
    fact('Departed before rejection', { url: '/notes', sameHeading: true, notice: ab('get', 'text', notice), rows: 0 });
    screenshots('04-departed');

    ab('find', 'role', 'link', 'click', '--name', 'Todos');
    ab('wait', '--text', 'Finished on the server');
    run('php', [app, 'revoke-view']);
    ab('wait', '--fn', 'document.querySelector("form") && document.querySelectorAll("li").length === 0');
    run('php', [app, 'restore-view']);
    ab('wait', '--text', 'Finished on the server');
    run('php', [app, 'revoke-viewAny']);
    wait('[data-zero-access="404"]');
    assert.equal(ab('get', 'count', 'form'), '0');
    assert.equal(count('Finished on the server'), '1');
    fact('Independent class and row permissions', {
        noView: 'form and empty list',
        noViewAny: '404 without form',
        storedRow: 1,
    });
    assert.doesNotMatch(text('requests'), /^(POST|PATCH|PUT|DELETE) \/todos/m);
    if (artifacts) writeFileSync(join(artifacts, 'observations.json'), JSON.stringify(facts, null, 2));
    console.log('Todo browser proof passed.');
} catch (error) {
    if (launched) {
        for (const command of [['get', 'url'], ['snapshot'], ['errors'], ['console']]) {
            try {
                console.error(ab(...command).slice(-2000));
            } catch {
                /* Browser may not have started. */
            }
        }
    }
    const diagnostics = ['requests', 'results', 'services.log', 'storage/logs/laravel.log']
        .map((name) => `${name}: ${text(name).slice(-6000)}`)
        .join('\n');
    console.error(
        diagnostics
            .replaceAll(env.ZERO_API_KEY, '[redacted]')
            .replace(/postgres(?:ql)?:\/\/\S+/g, '[database redacted]'),
    );
    throw error;
} finally {
    release();
    if (launched) {
        try {
            ab('close');
        } catch {
            /* already closed */
        }
    }
    for (const worker of workers.toReversed()) await stop(worker);
    if (created) {
        await until(
            () =>
                sql(`SELECT count(*) FROM pg_replication_slots WHERE database='${db}' AND active`, 'postgres') === '0',
            'Fixture replication still active',
        );
        sql(
            `SELECT pg_drop_replication_slot(slot_name) FROM pg_replication_slots WHERE database='${db}' AND NOT active`,
            'postgres',
        );
        sql(`DROP DATABASE "${db}" WITH (FORCE)`, 'postgres');
    }
    rmSync(root, { recursive: true, force: true });
}
