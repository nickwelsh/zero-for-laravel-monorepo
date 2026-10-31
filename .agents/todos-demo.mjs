import { spawn } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { setTimeout } from 'node:timers/promises';

process.chdir(fileURLToPath(new URL('../examples/todos/', import.meta.url)));
process.loadEnvFile('.env');
const credentials = 'storage/app/zero/orb-credentials.json';
if (!existsSync(credentials)) {
    writeFileSync(
        credentials,
        JSON.stringify({
            APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
            ZERO_API_KEY: randomBytes(32).toString('hex'),
        }),
        { mode: 0o600 },
    );
}
Object.assign(process.env, JSON.parse(readFileSync(credentials, 'utf8')), {
    APP_NAME: 'Todos',
    APP_ENV: 'local',
    APP_URL: 'http://localhost:8090',
    ASSET_URL: '',
    DB_CONNECTION: 'pgsql',
    DB_URL: '',
    DB_HOST: '127.0.0.1',
    DB_PORT: '55433',
    DB_DATABASE: 'laravel_zero_todos_demo',
    DB_USERNAME: 'postgres',
    DB_PASSWORD: '',
    SESSION_DOMAIN: 'null',
    SESSION_SECURE_COOKIE: 'false',
    TODOS_ORB_PROXY: 'true',
    VITE_APP_NAME: 'Todos',
    VITE_APP_URL: 'http://localhost:8090',
    VITE_ZERO_CACHE_URL: '/',
    ZERO_REPLICA_FILE: 'storage/app/zero/orb-replica.db',
    ZERO_PORT: process.env.PORT ?? '4858',
    ZERO_APP_ID: 'todos',
    ZERO_UPSTREAM_SCHEMA: 'todos_0',
    ZERO_PUBLICATION: 'laravel_zero_todos',
    TOKIO_WORKER_THREADS: '2',
    RAYON_NUM_THREADS: '2',
});

async function run(command, args) {
    const child = spawn(command, args, { stdio: 'inherit' });
    const forward = (signal) => child.kill(signal);
    process.on('SIGTERM', forward);
    process.on('SIGINT', forward);
    try {
        await new Promise((resolve, reject) => {
            child.once('error', reject);
            child.once('exit', (code, signal) => {
                if (code === 0) resolve();
                else reject(new Error(`${command} exited with ${signal ?? code}`));
            });
        });
    } finally {
        process.off('SIGTERM', forward);
        process.off('SIGINT', forward);
    }
}

switch (process.argv[2]) {
    case 'prepare':
        await run('php', ['artisan', 'config:clear']);
        await run('php', ['artisan', 'migrate', '--force', '--no-interaction']);
        await run('php', ['artisan', 'db:seed', '--class=TodosDemoSeeder', '--no-interaction']);
        await run('pnpm', ['build']);
        break;
    case 'app':
        // PostgreSQL is independently supervised; tolerate service startup ordering.
        for (let attempt = 0; ; attempt++) {
            try {
                await run('pg_isready', ['-h', '127.0.0.1', '-p', process.env.DB_PORT, '-q']);
                break;
            } catch (error) {
                if (attempt >= 59) throw error;
                await setTimeout(500);
            }
        }
        await run('php', ['artisan', 'serve', '--host=127.0.0.1', `--port=${process.env.PORT}`, '--no-reload']);
        break;
    case 'cache':
        await run(process.execPath, ['scripts/start-zero.mjs']);
        break;
    default:
        throw new Error('Expected prepare, app, or cache');
}
