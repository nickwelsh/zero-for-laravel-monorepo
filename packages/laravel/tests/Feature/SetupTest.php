<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use ZeroForLaravel\Tests\HttpTestCase;

uses(HttpTestCase::class);

test('session setup writes both environment files without sharing the key or replacing existing frontend work', function (): void {
    /** @var HttpTestCase $this */
    $base = base_path();
    $dir = sys_get_temp_dir().'/zero-setup-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($dir);
    app()->setBasePath($dir);
    app()->useEnvironmentPath($dir)->loadEnvironmentFrom('.env');
    app()->instance('env', 'local');
    config(['zero.generation.output' => $dir.'/generated.ts', 'zero.dev.port' => 4859]);
    File::put($dir.'/.env', "APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
    File::put($dir.'/.env.example', "APP_URL=http://localhost\nZERO_API_KEY=remove-this\n");
    File::put($dir.'/package.json', '{"dependencies":{"react":"^19"}}');
    $path = getenv('PATH');
    // A Herd executable is enough for detection; the wizard must never run it.
    File::put($dir.'/herd', "#!/bin/sh\nexit 1\n");
    chmod($dir.'/herd', 0755);
    putenv('PATH='.$dir.PATH_SEPARATOR.$path);
    try {
        $run = function (bool $apply): void {
            $command = $this->artisan('zero:setup');
            $this->assertInstanceOf(PendingCommand::class, $command);
            $command->expectsConfirmation('Configure Inertia live page props?', 'yes')
                ->expectsConfirmation('Generate Zero page prop types with Wayfinder?', 'yes')
                ->expectsConfirmation('Authenticate Zero with Laravel session cookies?', 'yes')
                ->expectsConfirmation('Publish context classes for fields beyond user_id?', 'no')
                ->expectsConfirmation('Publish managed permissions config and migrations?', 'no')
                ->expectsConfirmation('Publish editable config/zero.php?', 'no')
                ->expectsConfirmation('Use Laravel Herd?', 'yes')->expectsConfirmation('Use HTTPS with Herd?', 'yes')
                ->expectsQuestion('Laravel app URL', 'https://notes.test')
                ->expectsConfirmation('Apply these local settings to the app and .env.example?', $apply ? 'yes' : 'no')
                ->assertSuccessful()->run();
        };
        $run(false);
        expect(File::get($dir.'/.env'))->toBe("APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
        $run(true);
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

        expect(File::get($dir.'/zero.config.ts'))->not->toContain('queryHeaders', 'mutateHeaders');
        expect(File::exists($dir.'/inertia.ts'))->toBeTrue();
        File::put($dir.'/zero.config.ts', '// keep application options');
        $run(true);
        expect(Dotenv::parse(File::get($dir.'/.env')))->toBe($env);
        expect(File::get($dir.'/zero.config.ts'))->toBe('// keep application options');
    } finally {
        putenv('PATH='.$path);
        app()->setBasePath($base);
        File::deleteDirectory($dir);
    }
});
