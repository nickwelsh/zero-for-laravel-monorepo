<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Publication\PublicationManager;
use ZeroForLaravel\Tests\PostgresTestCase;
use ZeroForLaravel\ZeroServiceProvider;

#[ZeroPublic]
class MigrationRecord extends Model
{
    protected $table = 'tasks';

    protected $connection = 'testing';

    protected $hidden = ['secret'];
}

abstract class MigrationPublicationTestCase extends PostgresTestCase
{
    private bool $ownsMigrationArtifacts = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ownsMigrationArtifacts = true;
    }

    protected function tearDown(): void
    {
        if ($this->ownsMigrationArtifacts && isset($this->app) && $this->app !== null) {
            DB::statement('DROP PUBLICATION IF EXISTS laravel_zero_test_publication');
            Schema::dropIfExists('migrations');
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.models', [MigrationRecord::class]);
        $app['config']->set('zero.publication', 'laravel_zero_test_publication');
        $app['config']->set('zero.sync_publication_on_migrate', true);
    }
}

uses(MigrationPublicationTestCase::class);

test('fresh migrations reattach recreated tables using fresh private column metadata', function (): void {
    /** @var MigrationPublicationTestCase $this */
    $this->assertSame(0, Artisan::call('migrate:install'));
    $registry = resolve(ModelRegistry::class);
    resolve(PublicationManager::class)->sync('laravel_zero_test_publication');

    $this->assertSame(0, Artisan::call('migrate:fresh', [
        '--path' => __DIR__.'/../Fixtures/migrations', '--realpath' => true, '--force' => true,
    ]));

    $this->assertNotSame($registry, resolve(ModelRegistry::class));
    $this->assertSame(['tasks'], array_column(DB::select("SELECT tablename FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"), 'tablename'));
    $columns = DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'");
    $this->assertIsString($columns);
    $this->assertStringContainsString('body', $columns);
    $this->assertStringNotContainsString('secret', $columns);
});

test('dry runs rollbacks and disabled synchronization do not alter publications', function (string $method, bool $pretend, bool $enabled): void {
    /** @var MigrationPublicationTestCase $this */
    config()->set('zero.sync_publication_on_migrate', $enabled);
    Event::dispatch(new MigrationsEnded($method, ['pretend' => $pretend]));
    $this->assertNull(DB::scalar("SELECT pubname FROM pg_publication WHERE pubname = 'laravel_zero_test_publication'"));
})->with([
    'dry run' => ['up', true, true],
    'rollback' => ['down', false, true],
    'disabled' => ['up', false, false],
]);

test('migrations on another connection do not synchronize the upstream publication', function (): void {
    /** @var MigrationPublicationTestCase $this */
    config()->set('database.connections.other', config('database.connections.testing'));
    resolve(Migrator::class)->usingConnection('other', fn () => Event::dispatch(new MigrationsEnded('up')));
    $this->assertNull(DB::scalar("SELECT pubname FROM pg_publication WHERE pubname = 'laravel_zero_test_publication'"));
});

test('a migration can release column membership without resolving final metadata at an intermediate schema', function (): void {
    resolve(PublicationManager::class)->sync('laravel_zero_test_publication');
    $resolved = false;
    app()->resolving(ModelRegistry::class, function () use (&$resolved): void {
        $resolved = true;
    });
    app()->forgetInstance(ModelRegistry::class);
    PublicationManager::releaseTable('tasks', 'testing');
    expect($resolved)->toBeFalse();
    Schema::table('tasks', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->dropColumn('title'));
    expect(DB::select("SELECT tablename FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"))->toBe([]);
    // A later successful migration supplies the final model's required column.
    Schema::table('tasks', fn (\Illuminate\Database\Schema\Blueprint $table) => $table->string('title'));
    Event::dispatch(new MigrationsEnded('up'));
    $columns = DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'");
    expect($columns)->toContain('title');
    expect($columns)->not->toContain('secret');
});

test('disabled and pretend publication releases leave membership unchanged', function (): void {
    resolve(PublicationManager::class)->sync('laravel_zero_test_publication');
    config()->set('zero.sync_publication_on_migrate', false);
    PublicationManager::releaseTable('tasks', 'testing');
    config()->set('zero.sync_publication_on_migrate', true);
    DB::connection()->pretend(fn () => PublicationManager::releaseTable('tasks', 'testing'));
    expect(DB::scalar("SELECT count(*) FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"))->toBe(1);
});

test('failed batches leave released membership diagnosable until the repaired batch succeeds', function (): void {
    /** @var MigrationPublicationTestCase $this */
    resolve(PublicationManager::class)->sync('laravel_zero_test_publication');
    $directory = sys_get_temp_dir().'/zero-migrations-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $first = $directory.'/2026_10_26_000001_release_title.php';
    $second = $directory.'/2026_10_26_000002_rebuild_title.php';
    file_put_contents($first, <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \ZeroForLaravel\Publication\PublicationManager::releaseTable('tasks', 'testing');
        \Illuminate\Support\Facades\Schema::table('tasks', fn ($table) => $table->dropColumn('title'));
    }
};
PHP);
    file_put_contents($second, str_replace('RECOVERY_FILE', var_export($directory.'/recover', true), <<<'PHP'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        if (! file_exists(RECOVERY_FILE)) { throw new \RuntimeException('fixture interruption'); }
        \Illuminate\Support\Facades\Schema::table('tasks', fn ($table) => $table->string('title'));
    }
};
PHP));
    try {
        try {
            Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]);
            $this->fail('Expected the second migration to fail.');
        } catch (RuntimeException $error) {
            expect($error->getMessage())->toBe('fixture interruption');
        }

        expect(DB::select("SELECT tablename FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"))->toBe([]);
        expect(Schema::hasColumn('tasks', 'title'))->toBeFalse();
        // Repair the failed migration. Laravel resumes after the committed first migration.
        touch($directory.'/recover');
        expect(Artisan::call('migrate', ['--path' => $directory, '--realpath' => true, '--force' => true]))->toBe(0);
        expect(DB::scalar("SELECT attnames::text FROM pg_publication_tables WHERE pubname = 'laravel_zero_test_publication'"))->toContain('title');
    } finally {
        if (file_exists($directory.'/recover')) {
            File::delete($directory.'/recover');
        }

        File::delete($first);
        File::delete($second);
        rmdir($directory);
    }
});
