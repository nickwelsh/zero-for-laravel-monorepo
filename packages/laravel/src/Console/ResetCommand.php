<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use LogicException;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;
use ZeroForLaravel\Development\LocalEnvironment;

final class ResetCommand extends Command
{
    protected $signature = 'zero:reset {--dry-run : Show exact replica files and database artifacts without deleting} {--force : Explicitly authorize local replica-file deletion}';

    protected $description = "Reset only this workspace's owned local replica files, preserving all PostgreSQL data";

    public function handle(LocalEnvironment $local): int
    {
        $lock = null;
        $connection = null;
        $advisory = false;
        try {
            $local->assertLocal();
            $local->binary();
            $replica = $local->replica();
            $manifest = $replica.'.owner.json';
            if (! is_file($manifest) || is_link($manifest)) {
                throw new LogicException('No owned replica manifest. Start this workspace with zero:dev before using zero:reset.');
            }

            $owner = json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
            $connection = $local->connection();
            $id = config()->string('zero.app_id');
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $id) || ! is_array($owner) ||
                ($owner['version'] ?? null) !== '1.10.0' || ($owner['workspace'] ?? null) !== realpath(base_path()) ||
                ($owner['app'] ?? null) !== $id || ($owner['replica'] ?? null) !== $replica ||
                ($owner['database'] ?? null) !== $connection->getDatabaseName() ||
                ($owner['host'] ?? null) !== $connection->getConfig('host') ||
                ($owner['port'] ?? null) !== $connection->getConfig('port') ||
                $connection->getDriverName() !== 'pgsql' || config()->string('zero.upstream_schema') !== $id.'_0') {
                throw new LogicException('Replica ownership does not match this workspace, database, app ID, or supported Zero version.');
            }

            $this->line('Database: '.$connection->getDatabaseName().'; app ID: '.$id);
            $schemas = [$id, $id.'_0', $id.'_0/cvr', $id.'_0/cdc'];
            foreach ($schemas as $schema) {
                foreach ($connection->select('SELECT tablename FROM pg_tables WHERE schemaname = ? ORDER BY tablename', [$schema]) as $table) {
                    if (! is_object($table) || ! is_string($table->tablename ?? null)) {
                        throw new LogicException('Invalid database artifact metadata.');
                    }

                    $this->line('PRESERVE database artifact: '.$schema.'.'.$table->tablename);
                }
            }

            $slots = [$id.'_0']; // Legacy exact slot; current slots come from the exact shard catalog.
            if ($connection->scalar('SELECT to_regclass(?)', ['"'.$id.'_0".replicas']) !== null) {
                foreach ($connection->select('SELECT slot FROM "'.$id.'_0".replicas') as $row) {
                    if (! is_object($row) || ! is_string($row->slot ?? null)) {
                        throw new LogicException('Invalid replica slot metadata.');
                    }

                    $slots[] = $row->slot;
                }
            }

            foreach ($slots as $slot) {
                $active = $connection->scalar('SELECT active FROM pg_replication_slots WHERE slot_name = ?', [$slot]);
                if ($active) {
                    throw new LogicException('An associated replication slot is active. Stop Zero before resetting the replica.');
                }

                if ($active !== null) {
                    $this->line('PRESERVE replication slot: '.$slot);
                }
            }

            $targets = [];
            foreach (['', '-serving-copy', '-change-log'] as $database) {
                foreach (['', '-wal', '-shm', '-journal'] as $sidecar) {
                    $path = $replica.$database.$sidecar;
                    if (is_link($path) || (file_exists($path) && ! is_file($path))) {
                        throw new LogicException('Refusing non-regular replica paths.');
                    }

                    if (is_file($path)) {
                        $targets[] = $path;
                        $this->line('DELETE file: '.$path);
                    }
                }
            }

            $this->comment('Application tables, publications, mutation progress, CVRs, and change history are preserved. No database cleanup or wildcard file deletion is performed.');
            if ($this->option('dry-run')) {
                return self::SUCCESS;
            }

            if (! $this->option('force')) {
                throw new LogicException('Review zero:reset --dry-run, then pass --force to authorize deleting these local replica files.');
            }

            if (! $local->portAvailable()) {
                throw new LogicException('The configured Zero port is occupied. Stop Zero before resetting.');
            }

            $lock = fopen($replica.'.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new LogicException('The replica is in use by zero:dev.');
            }

            $advisory = (bool) $connection->scalar('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', ['zero-for-laravel:dev:'.$id]);
            if (! $advisory) {
                throw new LogicException('The database/app ID is in use by zero:dev.');
            }

            $lsof = (new ExecutableFinder)->find('lsof');
            if ($lsof === null) {
                throw new LogicException('Install lsof to verify that replica files are not open before resetting.');
            }

            foreach ($targets as $target) {
                $result = Process::run([$lsof, '-t', '--', $target]);
                if ($result->exitCode() !== 1 || $result->output() !== '') {
                    throw new LogicException('A replica file is open or its process ownership could not be verified. Stop associated processes first.');
                }
            }

            foreach ($slots as $slot) {
                if ($connection->scalar('SELECT active FROM pg_replication_slots WHERE slot_name = ?', [$slot])) {
                    throw new LogicException('A replication slot became active. Reset aborted.');
                }
            }

            foreach ($targets as $target) {
                if (! File::delete($target)) {
                    throw new LogicException('A replica file could not be removed. Inspect the dry run before retrying.');
                }
            }

            $this->info('Local replica files reset. Run zero:dev to rebuild them.');

            return self::SUCCESS;
        } catch (LogicException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Reset could not verify safety. No database cleanup was attempted; run zero:doctor.');

            return self::FAILURE;
        } finally {
            if ($advisory && $connection instanceof \Illuminate\Database\Connection) {
                $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['zero-for-laravel:dev:'.config()->string('zero.app_id')]);
            }

            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
