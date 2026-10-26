<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use LogicException;
use Throwable;
use ZeroForLaravel\Development\LocalEnvironment;
use ZeroForLaravel\Metadata\ModelRegistry;

final class DevCommand extends Command
{
    protected $signature = 'zero:dev';

    protected $description = 'Generate contracts, synchronize publication, and run the installed Zero cache locally';

    public function handle(LocalEnvironment $local, Filesystem $files): int
    {
        $lock = null;
        $connection = null;
        $environment = [];
        $advisory = false;
        try {
            $local->assertLocal();
            $binary = $local->binary();
            if (! $local->portAvailable()) {
                throw new LogicException('The configured Zero port is occupied. Stop its owner or change zero.dev.port.');
            }

            $registry = resolve(ModelRegistry::class);
            $connection = $registry->connection();
            $environment = $local->environment($connection);
            $files->ensureDirectoryExists(dirname($local->replica()));
            $lock = fopen($local->replica().'.lock', 'c');
            if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
                throw new LogicException('The configured replica is already in use by zero:dev.');
            }

            $advisory = (bool) $connection->scalar('SELECT pg_try_advisory_lock(hashtextextended(?, 0))', ['zero-for-laravel:dev:'.config()->string('zero.app_id')]);
            if (! $advisory) {
                throw new LogicException('This database/app ID is already in use by zero:dev.');
            }

            if ($this->call('zero:generate') !== self::SUCCESS || $this->call('zero:sync-publication') !== self::SUCCESS) {
                return self::FAILURE;
            }

            $files->replace($local->replica().'.owner.json', json_encode([
                'version' => '1.10.0', 'workspace' => realpath(base_path()),
                'app' => config()->string('zero.app_id'), 'database' => $connection->getDatabaseName(),
                'host' => $connection->getConfig('host'), 'port' => $connection->getConfig('port'),
                'replica' => $local->replica(),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
            $buffer = '';
            $emit = function (string $type, string $output) use (&$buffer, $local, $environment): void {
                $buffer .= $output;
                while (($end = strpos($buffer, "\n")) !== false) {
                    $this->output->write($local->redact(substr($buffer, 0, $end + 1), $environment));
                    $buffer = substr($buffer, $end + 1);
                }
            };
            $child = Process::path(base_path())->forever()->env($environment)->start($binary, $emit);
            $this->trap([SIGINT, SIGTERM], function (int $signal) use ($child): void {
                if ($child->running()) {
                    $child->signal($signal);
                }
            });
            $result = $child->wait();
            if ($buffer !== '') {
                $this->output->write($local->redact($buffer, $environment));
            }

            return $result->exitCode() ?? self::FAILURE;
        } catch (LogicException $error) {
            $this->error($local->redact($error->getMessage(), $environment));

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Zero startup failed. Run zero:doctor to inspect configuration and prerequisites.');

            return self::FAILURE;
        } finally {
            if ($advisory && $connection !== null) {
                $connection->select('SELECT pg_advisory_unlock(hashtextextended(?, 0))', ['zero-for-laravel:dev:'.config()->string('zero.app_id')]);
            }

            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
