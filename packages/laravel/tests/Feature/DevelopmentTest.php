<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use LogicException;
use ZeroForLaravel\Development\LocalEnvironment;
use ZeroForLaravel\Tests\OwnerPostgresTestCase as PostgresTestCase;
use ZeroForLaravel\ZeroServiceProvider;

abstract class DevelopmentTestCase extends PostgresTestCase
{
    public string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixture = sys_get_temp_dir().'/zero-dev-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->fixture.'/node_modules/@rocicorp/zero/out/zero/src');
        File::put($this->fixture.'/node_modules/@rocicorp/zero/package.json', '{"version":"1.11.4"}');
        File::put($this->fixture.'/node_modules/@rocicorp/zero/out/zero/src/cli.js', '// fixture binary');
        app()->setBasePath($this->fixture);
        app()->instance('env', 'local');

        config()->set('zero.generation.output', $this->fixture.'/generated.ts');
        config()->set('zero.dev.replica', $this->fixture.'/replica.db');

        $socket = stream_socket_server('tcp://0.0.0.0:0');
        if ($socket === false) {
            throw new LogicException('Could not allocate a fixture port.');
        }

        config()->set('zero.dev.port', (int) explode(':', (string) stream_socket_get_name($socket, false))[1]);
        fclose($socket);
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
            File::deleteDirectory($this->fixture);
        }

        parent::tearDown();
    }

    public function owner(): void
    {
        $local = new LocalEnvironment;
        $db = DB::connection();
        File::put($local->replica().'.owner.json', json_encode([
            'version' => '1.11.4', 'workspace' => realpath(base_path()), 'app' => 'fixture',
            'database' => $db->getDatabaseName(), 'host' => $db->getConfig('host'), 'port' => $db->getConfig('port'),
            'replica' => $local->replica(),
        ], JSON_THROW_ON_ERROR));
    }

    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.api_key', 'private-fixture-key');
        $app['config']->set('zero.publication', 'laravel_zero_test_publication');
        $app['config']->set('zero.app_id', 'fixture');
        $app['config']->set('zero.upstream_schema', 'fixture_0');
        $app['config']->set('app.url', 'https://application.test');
    }
}

uses(DevelopmentTestCase::class);

test('development commands refuse production before changing files or starting processes', function (): void {
    app()->instance('env', 'production');
    Process::fake();
    expect(Artisan::call('zero:dev'))->toBe(1);
    expect(Artisan::call('zero:reset', ['--force' => true]))->toBe(1);
    Process::assertNothingRan();
});

test('startup encodes PostgreSQL credentials, respects callback prefixes and forwards cookies', function (): void {
    /** @var DevelopmentTestCase $this */
    config()->set('zero.route_prefix', 'callbacks/private-zero');
    $local = new LocalEnvironment;
    $config = config()->array('database.connections.testing');
    $config['username'] = 'user@name';
    $config['password'] = 'p:/?#% word';
    $config['database'] = 'db/name';
    config()->set('database.connections.urlfixture', $config);
    expect($local->databaseUrl(DB::connection('urlfixture')))->toContain('user%40name:p%3A%2F%3F%23%25%20word@', '/db%2Fname?');
    $env = $local->environment(DB::connection());
    expect($env['ZERO_QUERY_URL'])->toBe('https://application.test/callbacks/private-zero/query');
    expect($env['ZERO_QUERY_FORWARD_COOKIES'])->toBe('true');

    config()->set('zero.route_prefix', '');
    expect($local->environment(DB::connection())['ZERO_QUERY_URL'])->toBe('https://application.test/query');
    config()->set('zero.route_prefix', 'callbacks/private-zero');
    expect($local->redact($env['ZERO_UPSTREAM_DB'].' '.$env['ZERO_QUERY_API_KEY'], $env))->not->toContain('private-fixture-key', 'postgresql://');
    File::put($this->fixture.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    File::put($this->fixture.'/metadata.json', json_encode([
        'version' => 1, 'inputs' => [], 'fields' => ['Input' => ['props' => (object) []]],
        'components' => (object) [], 'groups' => (object) [],
    ], JSON_THROW_ON_ERROR));
    config(['zero.formbraid.enabled' => true, 'zero.formbraid.output' => 'forms',
        'formbraid.metadata' => 'metadata.json', 'formbraid.component_enum' => 'app/Formbraid/DevStartupFields.php']);
    Process::fake(['*' => Process::result(output: 'cache stopped', exitCode: 7)]);
    expect(Artisan::call('zero:dev'))->toBe(7, Artisan::output());
    expect(is_file($this->fixture.'/generated.ts'))->toBeTrue();
    expect(is_file($this->fixture.'/generated.react.ts'))->toBeTrue();
    expect(is_file($this->fixture.'/forms/.formbraid-manifest.json'))->toBeTrue();
    expect(is_file($this->fixture.'/replica.db.owner.json'))->toBeTrue();
    Process::assertRan(fn (PendingProcess $process): bool => $process->environment['ZERO_QUERY_URL'] === $env['ZERO_QUERY_URL'] && $process->environment['ZERO_MUTATE_API_KEY'] === 'private-fixture-key');

    config(['formbraid.metadata' => 'missing.json']);
    expect(Artisan::call('zero:dev'))->toBe(1);
    expect(Artisan::output())->toContain('missing.json');
    Process::assertRanTimes(fn (PendingProcess $process): bool => $process->environment['ZERO_QUERY_URL'] === $env['ZERO_QUERY_URL'], 1);
});

test('occupied ports fail without starting or killing a process', function (): void {
    $socket = stream_socket_server('tcp://0.0.0.0:0');
    if ($socket === false) {
        throw new LogicException('Could not allocate a fixture port.');
    }

    try {
        config()->set('zero.dev.port', (int) explode(':', (string) stream_socket_get_name($socket, false))[1]);
        Process::fake();
        expect(Artisan::call('zero:dev'))->toBe(1);
        expect(Artisan::output())->toContain('occupied');
        Process::assertNothingRan();
    } finally {
        fclose($socket);
    }
});

test('reset dry runs show exact files and force only deletes this owned replica', function (): void {
    /** @var DevelopmentTestCase $this */
    $this->owner();
    File::put($this->fixture.'/replica.db', 'isolated fixture');
    File::put($this->fixture.'/replica.db-wal', 'fixture WAL');
    File::put($this->fixture.'/replica.db-other-app', 'preserve');
    expect(Artisan::call('zero:reset', ['--dry-run' => true]))->toBe(0);
    expect(is_file($this->fixture.'/replica.db'))->toBeTrue();
    expect(Artisan::call('zero:reset'))->toBe(1);
    Process::fake(['*' => Process::result(exitCode: 1)]);
    expect(Artisan::call('zero:reset', ['--force' => true]))->toBe(0);
    expect(is_file($this->fixture.'/replica.db'))->toBeFalse();
    expect(is_file($this->fixture.'/replica.db-wal'))->toBeFalse();
    expect(is_file($this->fixture.'/replica.db-other-app'))->toBeTrue();
    expect(DB::getSchemaBuilder()->hasTable('tasks'))->toBeTrue();
});

test('reset refuses foreign manifests, symbolic links and open files', function (): void {
    /** @var DevelopmentTestCase $this */
    $this->owner();
    File::put($this->fixture.'/replica.db', 'fixture');
    Process::fake(['*' => Process::result(output: '12345', exitCode: 0)]);
    expect(Artisan::call('zero:reset', ['--force' => true]))->toBe(1);
    expect(is_file($this->fixture.'/replica.db'))->toBeTrue();
    File::delete($this->fixture.'/replica.db');
    File::put($this->fixture.'/foreign.db', 'foreign');
    symlink($this->fixture.'/foreign.db', $this->fixture.'/replica.db');
    expect(Artisan::call('zero:reset', ['--force' => true]))->toBe(1);
    expect(File::get($this->fixture.'/foreign.db'))->toBe('foreign');
});

test('doctor separates cache health from authentication and backfill readiness', function (): void {
    Http::fake(['*' => Http::response('OK')]);
    expect(Artisan::call('zero:doctor'))->toBe(1); // No generated contracts/publication yet.
    expect(Artisan::output())->toContain('Cache process health: OK', 'Callback authentication: UNKNOWN', 'Synchronization/backfill: UNSUPPORTED');
});
