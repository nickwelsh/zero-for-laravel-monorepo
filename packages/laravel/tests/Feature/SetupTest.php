<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Laravel\Prompts\Prompt;
use ZeroForLaravel\Tests\PostgresTestCase;
use ZeroForLaravel\ZeroServiceProvider;

uses(PostgresTestCase::class);

test('session setup writes both environment files without sharing the key or replacing existing frontend work', function (): void {
    /** @var PostgresTestCase $this */
    app()->register(ZeroServiceProvider::class);
    $base = base_path();
    $dir = sys_get_temp_dir().'/zero-setup-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($dir);
    app()->setBasePath($dir);
    app()->useEnvironmentPath($dir)->loadEnvironmentFrom('.env');
    app()->instance('env', 'local');
    $zero = resource_path('js/zero');
    config(['zero.generation.output' => $zero.'/generated/index.ts', 'zero.dev.port' => 4859]);
    $entry = resource_path('js/app.tsx');
    File::ensureDirectoryExists(resource_path('js/pages'));
    $starter = "import { createInertiaApp } from '@inertiajs/react';\nvoid createInertiaApp({\n    strictMode: true,\n});\n";
    File::put($entry, $starter);
    Prompt::fallbackWhen(true);
    File::put($dir.'/.env', "APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
    File::put($dir.'/.env.example', "APP_URL=http://localhost\nZERO_API_KEY=remove-this\n");
    File::put($dir.'/package.json', '{"packageManager":"pnpm@10.0.0","dependencies":{"react":"^19","zod":"^4"}}');
    File::put($dir.'/yarn.lock', ''); // An explicit packageManager takes precedence.
    File::put($dir.'/composer.json', json_encode(['autoload' => ['psr-4' => ['App\\' => 'app/']]], JSON_THROW_ON_ERROR));
    File::ensureDirectoryExists($dir.'/app');
    File::ensureDirectoryExists($dir.'/bootstrap/cache');
    File::put($dir.'/artisan', '<?php require '.var_export(dirname(__DIR__, 2).'/vendor/autoload.php', true).';'.<<<'PHP'

        $app = Illuminate\Foundation\Application::configure(basePath: __DIR__)
            ->withProviders([ZeroForLaravel\ZeroServiceProvider::class])->create();
        exit($app->handleCommand(new Symfony\Component\Console\Input\ArgvInput));
        PHP);
    $path = getenv('PATH');
    $environment = $_ENV;
    foreach (['herd', 'pnpm'] as $binary) {
        File::put($dir.'/'.$binary, "#!/bin/sh\nprintf '%s\\n' \"\$*\" >> {$binary}.calls\n");
        chmod($dir.'/'.$binary, 0755);
    }

    $_ENV['PATH'] = $dir.PATH_SEPARATOR.$path;
    putenv('PATH='.$dir.PATH_SEPARATOR.$path);
    try {
        $database = config()->string('database.default');
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        expect(Artisan::call('zero:setup', ['--no-interaction' => true]))->toBe(1);
        $output = Artisan::output();
        expect($output)->toContain('Zero requires PostgreSQL 15 or later');
        expect($output)->not->toContain('Detected React');
        config(['database.default' => $database]);

        $run = function (bool $apply, ?bool $resolver, bool $session = true, bool $install = true): void {
            $command = $this->artisan('zero:setup');
            $this->assertInstanceOf(PendingCommand::class, $command);
            $command->expectsChoice('Which integrations would you like to enable?', $session ? ['inertia', 'wayfinder'] : [], [
                '' => 'None', 'inertia' => 'Inertia live page props', 'wayfinder' => 'Wayfinder page prop types',
            ])->expectsQuestion('How would you like to authenticate?', $session ? 'session' : 'token')
                ->expectsChoice('Which files would you like to publish?', [], [
                    '' => 'None',
                    'context' => 'Context classes for fields beyond user_id',
                    'permissions' => 'Managed permissions config and migrations',
                    'config' => 'Editable config/zero.php for custom overrides',
                ]);
            if ($resolver !== null) {
                $command->expectsConfirmation('Add the live page resolver to resources/js/app.tsx?', $resolver ? 'yes' : 'no');
            }

            $command->expectsConfirmation('Install frontend dependencies with pnpm?', $install ? 'yes' : 'no')
                ->expectsConfirmation('Configure the site and cache proxy with Laravel Herd?', 'yes')->expectsConfirmation('Use HTTPS with Herd?', 'yes')
                ->expectsQuestion('Laravel app URL', 'https://notes.test')
                ->expectsConfirmation('Apply these settings and generate Zero contracts?', $apply ? 'yes' : 'no')
                ->assertSuccessful()->run();
        };
        $run(false, true);
        expect(File::exists($dir.'/herd.calls'))->toBeFalse();
        expect(File::exists($dir.'/pnpm.calls'))->toBeFalse();
        expect(File::get($entry))->toBe($starter);
        expect(File::exists($zero.'/generated/react.ts'))->toBeFalse();
        expect(File::get($dir.'/.env'))->toBe("APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
        $run(true, false, install: false);
        expect(File::exists($dir.'/pnpm.calls'))->toBeFalse();
        expect(File::get($dir.'/herd.calls'))->toBe("link notes\nsecure notes\nproxy zero.notes http://127.0.0.1:4859 --secure\n");
        expect(File::get($zero.'/generated/react.ts'))->toContain('laravelSession: true');
        expect(File::get($entry))->toBe($starter);
        $run(true, true);
        expect(File::get($dir.'/pnpm.calls'))->toBe("add --save-exact @nickwelsh/zero-for-laravel @rocicorp/zero@1.11.4\n");
        $installed = File::get($entry);
        expect($installed)->toContain("from './zero/inertia'", 'livePages.wrap(module.default, page)', 'strictMode: true');
        $env = Dotenv::parse(File::get($dir.'/.env'));
        $example = Dotenv::parse(File::get($dir.'/.env.example'));
        expect($env['ZERO_API_KEY'])->toMatch('/^[0-9a-f]{64}$/');
        expect($example['ZERO_API_KEY'])->toBe('');
        expect($example)->not->toHaveKey('PRIVATE_SETTING');
        foreach ([$env, $example] as $values) {
            expect($values['ZERO_INERTIA'])->toBe('true');
            expect($values['ZERO_WAYFINDER'])->toBe('true');
            expect($values['VITE_APP_URL'])->toBe('https://notes.test');
            expect($values['VITE_ZERO_CACHE_URL'])->toBe('https://zero.notes.test');
            expect($values['SESSION_DOMAIN'])->toBe('.notes.test');
            expect($values['ZERO_MUTATE_ALLOWED_CLIENT_HEADERS'])->toBe('x-xsrf-token');
        }

        expect(File::get($zero.'/zero.config.ts'))->not->toContain('queryHeaders', 'mutateHeaders');
        expect(File::get($zero.'/inertia.ts'))->toContain("from './generated/queries'");
        File::put($zero.'/zero.config.ts', '// keep application options');
        $run(true, null);
        expect(File::get($entry))->toBe($installed);
        expect(Dotenv::parse(File::get($dir.'/.env')))->toBe($env);
        expect(File::get($zero.'/zero.config.ts'))->toBe('// keep application options');
        $custom = str_replace('strictMode: true', 'resolve: myCustomResolver', $starter);
        File::put($entry, $custom);
        $run(true, null);
        expect(File::get($entry))->toBe($custom);
        $run(true, null, session: false);
        $tokenEnv = Dotenv::parse(File::get($dir.'/.env'));
        expect([$tokenEnv['ZERO_SESSION_AUTH'], $tokenEnv['ZERO_INERTIA'], $tokenEnv['ZERO_WAYFINDER']])->toBe(['false', 'false', 'false']);
        expect(File::get($zero.'/generated/react.ts'))->not->toContain('laravelSession: true');
    } finally {
        $_ENV = $environment;
        putenv('PATH='.$path);
        app()->setBasePath($base);
        File::deleteDirectory($dir);
    }
});
