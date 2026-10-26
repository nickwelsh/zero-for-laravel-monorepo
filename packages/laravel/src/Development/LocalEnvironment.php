<?php

declare(strict_types=1);

namespace ZeroForLaravel\Development;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Process\ExecutableFinder;
use ZeroForLaravel\Metadata\ModelDiscovery;

final class LocalEnvironment
{
    public function connection(): Connection
    {
        $classes = (new ModelDiscovery)->registered();

        return $classes === [] ? DB::connection() : (new $classes[0])->getConnection();
    }

    public function assertLocal(): void
    {
        if (! app()->environment('local')) {
            throw new LogicException('Zero development commands require APP_ENV=local.');
        }
    }

    public function port(): int
    {
        $port = config()->integer('zero.dev.port', 4848);
        if ($port < 1024 || $port > 65535) {
            throw new LogicException('zero.dev.port must be between 1024 and 65535.');
        }

        return $port;
    }

    public function portAvailable(): bool
    {
        $socket = @stream_socket_server('tcp://0.0.0.0:'.$this->port(), $code, $message);
        if ($socket === false) {
            return false;
        }

        fclose($socket);
        $connection = @stream_socket_client('tcp://[::1]:'.$this->port(), $code, $message, 0.2);
        if ($connection !== false) {
            fclose($connection);

            return false;
        }

        return true;
    }

    public function replica(): string
    {
        $path = config()->string('zero.dev.replica', storage_path('app/zero/replica.db'));
        if (! str_starts_with($path, DIRECTORY_SEPARATOR) || str_contains($path, "\0")) {
            throw new LogicException('zero.dev.replica must be an absolute file path.');
        }

        return $path;
    }

    /** @return array{string, string} */
    public function binary(): array
    {
        $node = (new ExecutableFinder)->find(config()->string('zero.dev.node', 'node'));
        $package = base_path('node_modules/@rocicorp/zero/package.json');
        if ($node === null || ! is_file($package)) {
            throw new LogicException('Install Node.js and @rocicorp/zero 1.10.0 in this application before running zero:dev.');
        }

        $metadata = json_decode((string) file_get_contents($package), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($metadata) || ($metadata['version'] ?? null) !== '1.10.0') {
            throw new LogicException('This package supports @rocicorp/zero 1.10.0.');
        }

        $cli = dirname($package).'/out/zero/src/cli.js';
        if (! is_file($cli)) {
            throw new LogicException('The installed Zero cache binary is missing. Reinstall @rocicorp/zero.');
        }

        return [$node, $cli];
    }

    public function databaseUrl(Connection $connection): string
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Zero requires PostgreSQL 15 or later.');
        }

        $host = $this->setting($connection, 'host', '127.0.0.1');
        if (str_starts_with($host, '/')) {
            throw new LogicException('zero:dev requires one TCP PostgreSQL host.');
        }

        $host = str_contains($host, ':') ? '['.trim($host, '[]').']' : $host;
        $url = 'postgresql://'.rawurlencode($this->setting($connection, 'username')).':'.rawurlencode($this->setting($connection, 'password')).'@'.$host.':'.$this->setting($connection, 'port', '5432').'/'.rawurlencode($connection->getDatabaseName());
        $sslmode = $this->setting($connection, 'sslmode', 'prefer');

        return $url.'?sslmode='.rawurlencode($sslmode);
    }

    /** @return array<string, string> */
    public function environment(Connection $connection): array
    {
        $key = config('zero.api_key');
        if (! is_string($key) || $key === '') {
            throw new LogicException('Set ZERO_API_KEY to a private callback key.');
        }

        $app = rtrim(config()->string('app.url'), '/');
        if (! filter_var($app, FILTER_VALIDATE_URL) || ! in_array(parse_url($app, PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new LogicException('APP_URL must be the HTTP(S) URL serving this Laravel application.');
        }

        $prefix = trim(config()->string('zero.route_prefix'), '/');
        $callback = $app.($prefix === '' ? '' : '/'.$prefix);
        $id = config()->string('zero.app_id');
        if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $id) || config()->string('zero.upstream_schema') !== $id.'_0') {
            throw new LogicException('Local Zero requires an app_id of up to 40 lowercase identifier characters and upstream_schema = app_id + _0. Use a unique app ID per worktree.');
        }

        $database = $this->databaseUrl($connection);
        $env = [
            'NODE_ENV' => 'development', 'ZERO_UPSTREAM_DB' => $database,
            'ZERO_CVR_DB' => $database, 'ZERO_CHANGE_DB' => $database,
            'ZERO_APP_ID' => $id, 'ZERO_SHARD_NUM' => '0',
            'ZERO_APP_PUBLICATIONS' => config()->string('zero.publication'),
            'ZERO_QUERY_URL' => $callback.'/query', 'ZERO_MUTATE_URL' => $callback.'/mutate',
            'ZERO_QUERY_API_KEY' => $key, 'ZERO_MUTATE_API_KEY' => $key,
            'ZERO_QUERY_FORWARD_COOKIES' => 'true', 'ZERO_MUTATE_FORWARD_COOKIES' => 'true',
            'ZERO_REPLICA_FILE' => $this->replica(), 'ZERO_PORT' => (string) $this->port(),
            'ZERO_NUM_SYNC_WORKERS' => '1', 'ZERO_LOG_LEVEL' => 'warn',
        ];
        $ca = config('zero.dev.ca');
        if ($ca !== null) {
            if (! is_string($ca) || ! is_readable($ca)) {
                throw new LogicException('zero.dev.ca must reference a readable CA certificate.');
            }

            $env['NODE_EXTRA_CA_CERTS'] = $ca;
        }

        return $env;
    }

    /** @param array<string, string> $environment */
    public function redact(string $output, array $environment): string
    {
        $database = $environment['ZERO_UPSTREAM_DB'] ?? '';
        $password = parse_url($database, PHP_URL_PASS);
        $secrets = array_filter([$database, $environment['ZERO_QUERY_API_KEY'] ?? '', is_string($password) ? $password : '', is_string($password) ? rawurldecode($password) : '']);
        foreach ($secrets as $secret) {
            $output = str_replace($secret, '[redacted]', $output);
        }

        // Zero errors may reconstruct a connection URL rather than echo the original.
        return preg_replace('~postgres(?:ql)?://[^\s"\x27]+~i', '[database redacted]', $output) ?? '[redacted]';
    }

    private function setting(Connection $connection, string $key, string $default = ''): string
    {
        $value = $connection->getConfig($key) ?? $default;
        if (! is_string($value) && ! is_int($value)) {
            throw new LogicException('PostgreSQL '.$key.' must be a scalar connection option.');
        }

        return (string) $value;
    }
}
