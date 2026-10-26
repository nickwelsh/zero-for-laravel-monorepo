<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use LogicException;
use Throwable;
use ZeroForLaravel\Development\LocalEnvironment;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Publication\PublicationManager;

final class DoctorCommand extends Command
{
    protected $signature = 'zero:doctor';

    protected $description = 'Read-only checks of Zero prerequisites, contracts, publication and process reachability';

    public function handle(LocalEnvironment $local): int
    {
        $failed = false;
        $check = function (string $name, string $state, string $message) use (&$failed): void {
            $failed = $failed || $state === 'FAIL';
            $this->line($name.': '.$state.' — '.$message);
        };
        try {
            $local->binary();
            $check('Zero binary', 'OK', '1.10.0 installed');
        } catch (Throwable) {
            $check('Zero binary', 'FAIL', 'Install Node.js and @rocicorp/zero 1.10.0 in the application.');
        }

        try {
            $connection = $local->connection();
            if ($connection->getDriverName() !== 'pgsql') {
                $check('PostgreSQL', 'FAIL', 'Requires PostgreSQL 15 or later.');
            } else {
                $version = $this->number($connection->scalar('SHOW server_version_num'));
                $check('PostgreSQL', $version >= 150000 ? 'OK' : 'FAIL', (string) $version);
                $wal = $connection->scalar('SHOW wal_level');
                if (! is_string($wal)) {
                    throw new LogicException('Invalid WAL configuration.');
                }

                $slots = $this->number($connection->scalar('SHOW max_replication_slots'));
                $senders = $this->number($connection->scalar('SHOW max_wal_senders'));
                $check('Logical replication', $wal === 'logical' && $slots > 0 && $senders > 0 ? 'OK' : 'FAIL', 'wal_level='.$wal.', slots='.$slots.', senders='.$senders);
                $role = $connection->selectOne('SELECT rolsuper, rolreplication, rolcreatedb FROM pg_roles WHERE rolname = current_user');
                if (! is_object($role) || ! is_bool($role->rolsuper ?? null) || ! is_bool($role->rolreplication ?? null) || ! is_bool($role->rolcreatedb ?? null)) {
                    throw new LogicException('Invalid role metadata.');
                }

                $check('Role', ($role->rolsuper || $role->rolreplication) ? 'OK' : 'FAIL', 'superuser='.($role->rolsuper ? 'yes' : 'no').', replication='.($role->rolreplication ? 'yes' : 'no').', create_database='.($role->rolcreatedb ? 'yes' : 'no').'; publication/table ownership must also be granted.');
                $check('DDL detection', $role->rolsuper ? 'OK' : 'UNKNOWN', 'Zero installs event triggers when permitted; managed PostgreSQL may require provider-specific setup.');
                $check('Environment', 'OK', 'database='.$connection->getDatabaseName().', app='.config()->string('zero.app_id').', upstream='.config()->string('zero.upstream_schema'));
            }
        } catch (Throwable) {
            $check('PostgreSQL', 'UNKNOWN', 'Unable to inspect the configured connection.');
        }

        try {
            $registry = resolve(ModelRegistry::class);
            $check('Schema metadata', 'OK', count($registry->all()).' registered models');
            $check('Generated contracts', $this->callSilent('zero:generate', ['--check' => true]) === self::SUCCESS ? 'OK' : 'FAIL', 'Run zero:generate after successful migrations and model changes.');
            $expected = [];
            foreach ($registry->all() as $model) {
                $expected[$model->table] = array_keys($model->columns);
            }

            $expected += $registry->pivots();
            if ($registry->connection()->getDriverName() === 'pgsql') {
                (new PublicationManager($registry))->plan(config()->string('zero.publication'));
                $actual = [];
                foreach ($registry->connection()->select('SELECT tablename, schemaname, unnest(attnames) AS column FROM pg_publication_tables WHERE pubname = ?', [config()->string('zero.publication')]) as $row) {
                    if (! is_object($row) || ! is_string($row->schemaname ?? null) || ! is_string($row->tablename ?? null) || ! is_string($row->column ?? null)) {
                        throw new LogicException('Invalid publication metadata.');
                    }

                    $actual[$row->schemaname === 'public' ? $row->tablename : $row->schemaname.'.'.$row->tablename][] = $row->column;
                }

                foreach ([$expected, $actual] as $index => $tables) {
                    foreach ($tables as &$columns) {
                        sort($columns);
                    }

                    unset($columns);
                    ksort($tables);
                    if ($index === 0) {
                        $expected = $tables;
                    } else {
                        $actual = $tables;
                    }
                }

                $unrestricted = $registry->connection()->selectOne('SELECT 1 FROM pg_publication_rel r JOIN pg_publication p ON p.oid = r.prpubid WHERE p.pubname = ? AND r.prattrs IS NULL LIMIT 1', [config()->string('zero.publication')]);
                $check('Publication', $actual === $expected && $unrestricted === null ? 'OK' : 'FAIL', 'Expected '.json_encode($expected, JSON_THROW_ON_ERROR).'; actual '.json_encode($actual, JSON_THROW_ON_ERROR).'. Explicit column lists are required. Repair migrations, then run zero:sync-publication.');
            } else {
                $check('Publication', 'UNKNOWN', 'PostgreSQL catalog inspection unavailable.');
            }
        } catch (Throwable) {
            $check('Schema metadata', 'FAIL', 'Cannot resolve final model schema. Repair or finish migrations before zero:generate and zero:sync-publication.');
        }

        $prefix = trim(config()->string('zero.route_prefix'), '/');
        $prefix = $prefix === '' ? '' : '/'.$prefix;

        $key = config('zero.api_key');
        $check('Callback configuration', is_string($key) && $key !== '' ? 'OK' : 'FAIL', 'Private key '.(is_string($key) && $key !== '' ? 'configured' : 'missing').'; routes '.$prefix.'/query and '.$prefix.'/mutate. Session cookies must reach Laravel.');
        $check('Callback authentication', 'UNKNOWN', 'Configuration alone cannot verify an authenticated cache-to-Laravel callback.');
        try {
            $port = $local->port();
            $available = $local->portAvailable();
            $check('Local port', $available ? 'OK' : 'UNKNOWN', $port.($available ? ' available' : ' occupied; process ownership unknown'));
            $response = Http::timeout(2)->get('http://127.0.0.1:'.$port.'/');
            $check('Cache process health', $response->successful() ? 'OK' : 'FAIL', 'HTTP '.$response->status().' from local root health endpoint');
        } catch (Throwable) {
            $check('Cache process health', 'UNKNOWN', 'No reachable local cache health endpoint.');
        }

        try {
            $path = $local->replica();
            $check('Replica file', 'UNKNOWN', $path.(is_file($path) ? ' exists' : ' missing').'; file presence does not establish synchronization.');
            $owner = is_file($path.'.owner.json') ? json_decode((string) file_get_contents($path.'.owner.json'), true, flags: JSON_THROW_ON_ERROR) : null;
            $matches = is_array($owner) && ($owner['workspace'] ?? null) === realpath(base_path()) && ($owner['app'] ?? null) === config('zero.app_id') && ($owner['database'] ?? null) === $local->connection()->getDatabaseName() && ($owner['replica'] ?? null) === $path;
            $check('Replica identity', $owner === null ? 'UNKNOWN' : ($matches ? 'OK' : 'FAIL'), $matches ? 'Ownership manifest matches workspace, database and app ID.' : 'No matching workspace/database/app ownership manifest.');
        } catch (Throwable) {
            $check('Replica identity', 'UNKNOWN', 'Cannot inspect replica path or ownership manifest.');
        }

        $check('Synchronization/backfill', 'UNSUPPORTED', 'Zero 1.10.0 exposes no general readiness endpoint here. Verify a subscribed query completes and observes an upstream update.');

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function number(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || ! ctype_digit($value))) {
            throw new LogicException('Expected numeric PostgreSQL metadata.');
        }

        return (int) $value;
    }
}
