<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Feature;

use Dotenv\Dotenv;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Laravel\Prompts\Prompt;
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
    $zero = resource_path('js/zero');
    config(['zero.generation.output' => $zero.'/generated/index.ts', 'zero.dev.port' => 4859]);
    $entry = resource_path('js/app.tsx');
    File::ensureDirectoryExists(resource_path('js/pages'));
    $starter = "import { createInertiaApp } from '@inertiajs/react';\nvoid createInertiaApp({\n    strictMode: true,\n});\n";
    File::put($entry, $starter);
    Prompt::fallbackWhen(true);
    File::put($dir.'/.env', "APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
    File::put($dir.'/.env.example', "APP_URL=http://localhost\nZERO_API_KEY=remove-this\n");
    File::put($dir.'/package.json', '{"dependencies":{"react":"^19"}}');
    $path = getenv('PATH');
    // A Herd executable is enough for detection; the wizard must never run it.
    File::put($dir.'/herd', "#!/bin/sh\nexit 1\n");
    chmod($dir.'/herd', 0755);
    putenv('PATH='.$dir.PATH_SEPARATOR.$path);
    try {
        $run = function (bool $apply, ?bool $resolver): void {
            $command = $this->artisan('zero:setup');
            $this->assertInstanceOf(PendingCommand::class, $command);
            $command->expectsConfirmation('Configure Inertia live page props?', 'yes')
                ->expectsConfirmation('Generate Zero page prop types with Wayfinder?', 'yes')
                ->expectsConfirmation('Authenticate Zero with Laravel session cookies?', 'yes')
                ->expectsChoice('Which files would you like to publish?', [], [
                    '' => 'None',
                    'context' => 'Context classes for fields beyond user_id',
                    'permissions' => 'Managed permissions config and migrations',
                    'config' => 'Editable config/zero.php for custom overrides',
                ]);
            if ($resolver !== null) {
                $command->expectsConfirmation('Add the live page resolver to resources/js/app.tsx?', $resolver ? 'yes' : 'no');
            }

            $command->expectsConfirmation('Use Laravel Herd?', 'yes')->expectsConfirmation('Use HTTPS with Herd?', 'yes')
                ->expectsQuestion('Laravel app URL', 'https://notes.test')
                ->expectsConfirmation('Apply these local settings to the app and .env.example?', $apply ? 'yes' : 'no')
                ->assertSuccessful()->run();
        };
        $run(false, true);
        expect(File::get($entry))->toBe($starter);
        expect(File::get($dir.'/.env'))->toBe("APP_URL=http://localhost\nPRIVATE_SETTING=keep-private\nZERO_API_KEY=\n");
        $run(true, false);
        expect(File::get($entry))->toBe($starter);
        $run(true, true);
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
    } finally {
        putenv('PATH='.$path);
        app()->setBasePath($base);
        File::deleteDirectory($dir);
    }
});
