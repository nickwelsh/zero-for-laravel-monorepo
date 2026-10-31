import { spawn } from 'node:child_process';
import { existsSync, mkdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join } from 'node:path';
import { createRequire } from 'node:module';

process.loadEnvFile('.env');
const require = createRequire(import.meta.url);
const replica = process.env.ZERO_REPLICA_FILE ?? join(process.cwd(), 'storage/app/zero/replica.db');
const certificate =
    process.env.ZERO_CA_CERT ??
    process.env.ZERO_HERD_CA ??
    join(homedir(), 'Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem');
if (!process.env.ZERO_API_KEY) throw new Error('Set ZERO_API_KEY in .env before starting Zero.');
mkdirSync(dirname(replica), { recursive: true });

const database = new URL('postgresql://127.0.0.1');
database.hostname = process.env.DB_HOST;
database.port = process.env.DB_PORT;
database.username = process.env.DB_USERNAME;
database.password = process.env.DB_PASSWORD ?? '';
database.pathname = `/${process.env.DB_DATABASE}`;
const app = process.env.APP_URL;
const child = spawn(process.execPath, [join(dirname(require.resolve('@rocicorp/zero')), 'cli.js')], {
    stdio: 'inherit',
    env: {
        ...process.env,
        NODE_ENV: 'development',
        ...(existsSync(certificate) ? { NODE_EXTRA_CA_CERTS: certificate } : {}),
        ZERO_UPSTREAM_DB: database.href,
        ZERO_CVR_DB: database.href,
        ZERO_CHANGE_DB: database.href,
        ZERO_APP_PUBLICATIONS: process.env.ZERO_PUBLICATION,
        ZERO_QUERY_URL: `${app}/zero/query`,
        ZERO_MUTATE_URL: `${app}/zero/mutate`,
        ZERO_QUERY_API_KEY: process.env.ZERO_API_KEY,
        ZERO_MUTATE_API_KEY: process.env.ZERO_API_KEY,
        ZERO_QUERY_FORWARD_COOKIES: 'true',
        ZERO_MUTATE_FORWARD_COOKIES: 'true',
        ZERO_REPLICA_FILE: replica,
        ZERO_PORT: process.env.ZERO_PORT ?? '4858',
        ZERO_NUM_SYNC_WORKERS: '1',
        ZERO_LOG_LEVEL: process.env.ZERO_LOG_LEVEL ?? 'warn',
    },
});
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal));
child.on('exit', (code) => process.exit(code ?? 0));
