<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravel\Wayfinder\Converters\InertiaData;
use LogicException;
use Symfony\Component\Process\ExecutableFinder;
use ZeroForLaravel\Development\LocalEnvironment;
use ZeroForLaravel\Development\SetupEnvironmentFile;
use ZeroForLaravel\Generation\AutoDetectFrontendDriver;
use ZeroForLaravel\Generation\FrontendDriver;
use ZeroForLaravel\Generation\ReactFrontendDriver;

final class SetupCommand extends Command
{
    protected $signature = 'zero:setup';

    protected $description = 'Configure Zero for this local application without starting services or migrating';

    public function handle(Filesystem $files, LocalEnvironment $local, FrontendDriver $frontend): int
    {
        try {
            $local->assertLocal();
            if (app()->configurationIsCached()) {
                throw new LogicException('Run php artisan config:clear before setup.');
            }

            $path = app()->environmentFilePath();
            if (! $files->isFile($path) || basename($path) === '.env.example') {
                throw new LogicException('Create a local .env before setup (copy .env.example and run php artisan key:generate).');
            }

            $frontend = $frontend instanceof AutoDetectFrontendDriver ? $frontend->detect() : $frontend;
            $this->info($frontend instanceof ReactFrontendDriver ? 'Detected React.' : ($frontend instanceof FrontendDriver ? 'Using configured frontend driver.' : 'No frontend detected.'));
            $inertia = class_exists(\Inertia\ServiceProvider::class) && $this->confirm('Configure Inertia live page props?', true);
            $wayfinder = class_exists(InertiaData::class) && $this->confirm('Generate Zero page prop types with Wayfinder?', true);
            $session = $this->confirm('Authenticate Zero with Laravel session cookies?', $inertia);
            $context = $this->confirm('Publish context classes for fields beyond user_id?');
            $permissions = $this->confirm('Publish managed permissions config and migrations?');
            $publish = $context || $this->confirm('Publish editable config/zero.php?');

            $herd = (new ExecutableFinder)->find('herd') !== null && $this->confirm('Use Laravel Herd?', true);
            [$app, $host, $secure] = $this->appOrigin($herd);
            $port = $local->port();
            $cache = $herd ? ($secure ? 'https://' : 'http://').'zero.'.$host : ($secure ? $app : 'http://'.$host.':'.$port);
            $settings = ['APP_URL' => $app, 'VITE_APP_URL' => '"${APP_URL}"', 'VITE_ZERO_CACHE_URL' => $cache,
                'ZERO_PORT' => (string) $port,
                'ZERO_WAYFINDER' => $wayfinder ? 'true' : 'false',
                'ZERO_INERTIA' => $inertia ? 'true' : 'false', 'ZERO_SESSION_AUTH' => $session ? 'true' : 'false'];
            if ($session) {
                $settings += ['SESSION_DOMAIN' => $herd ? '.'.$host : 'null', 'SESSION_SECURE_COOKIE' => $secure ? 'true' : 'false', 'SESSION_SAME_SITE' => 'lax',
                    'ZERO_QUERY_ALLOWED_CLIENT_HEADERS' => 'x-xsrf-token', 'ZERO_MUTATE_ALLOWED_CLIENT_HEADERS' => 'x-xsrf-token'];
            }

            $this->line("Laravel: {$app}  |  Zero cache: {$cache}");
            if ($session) {
                $this->line('Session domain: '.$settings['SESSION_DOMAIN'].'; CSRF validation stays enabled.');
            }

            if (! $herd && $secure) {
                $this->warn("Configure your HTTPS proxy to forward /sync/ WebSockets to http://127.0.0.1:{$port}.");
            }

            $private = new SetupEnvironmentFile($files, $path);
            $example = new SetupEnvironmentFile($files, base_path('.env.example'));
            $privateSettings = $settings;
            if ($herd && $secure) {
                $ca = getenv('HOME').'/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem';
                if (! config('zero.dev.ca') && is_readable($ca)) {
                    $privateSettings['ZERO_CA_CERT'] = '"'.addcslashes($ca, '\\"$').'"';
                } else {
                    $this->line('If Node cannot verify Herd TLS, set ZERO_CA_CERT in .env to the absolute path of the Herd CA.');
                }
            }

            $private->stage($privateSettings, true);
            $example->stage($settings, false);

            if (! $this->confirm('Apply these local settings to the app and .env.example?')) {
                return self::SUCCESS;
            }

            $private->save(true);
            $example->save(false);

            if ($publish) {
                $this->publish('zero-config');
            }

            if ($context) {
                $this->publish('zero-context');
                $config = config_path('zero.php');
                $original = $files->get($config);
                if ($original === $files->get(__DIR__.'/../../config/zero.php')) {
                    $files->replace($config, strtr($original, [
                        'use ZeroForLaravel\\Context\\ZeroContext;' => 'use App\\Enums\\ZeroContext;',
                        'use ZeroForLaravel\\Context\\ResolveZeroContext;' => 'use App\\Zero\\ResolveZeroContext;',
                    ]));
                } else {
                    $this->line('In config/zero.php, point context to App\\Enums\\ZeroContext::class and context_resolver to App\\Zero\\ResolveZeroContext::class.');
                }
            }

            if ($permissions) {
                $this->publish('permissions-config');
                // Published migration timestamps can differ. Match by migration name on reruns.
                foreach ($files->files(__DIR__.'/../../database/migrations') as $migration) {
                    $name = substr($migration->getFilename(), 18);
                    if ($files->glob(database_path('migrations/*_'.$name)) === []) {
                        $files->ensureDirectoryExists(database_path('migrations'));
                        $files->copy($migration->getPathname(), database_path('migrations/'.$migration->getFilename()));
                    }
                }

                $this->line('Configure permission bindings in config/permissions.php before migrating.');
            }

            $output = config()->string('zero.generation.output');
            $directory = dirname($output);
            $configuration = $frontend?->configuration();
            if ($configuration !== null) {
                $this->create($files, $directory.'/zero.config.ts', $configuration);
            }

            if ($frontend instanceof ReactFrontendDriver) {
                $this->line('Install @nickwelsh/zero-for-laravel, @rocicorp/zero@1.11.4 and zod. Wrap your signed-in layout in the generated ZeroProvider.');
                if ($inertia) {
                    $base = pathinfo($output, PATHINFO_FILENAME);
                    $this->create($files, $directory.'/inertia.ts', "import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';\nimport { bindings, navigation } from './{$base}.queries';\n\nexport const livePages = createInertiaAdapter(bindings, { navigation });\nexport const Link = livePages.Link;\n");
                    $this->line('Use livePages.wrap in your Inertia page resolver (@inertiajs/react ^3.8).');
                }
            }

            if ($herd) {
                $site = substr($host, 0, -5);
                $this->line('herd link '.$site);
                if ($secure) {
                    $this->line('herd secure '.$site);
                }

                $this->line("herd proxy zero.{$site} http://127.0.0.1:{$port}".($secure ? ' --secure' : ''));
            }

            $this->info('After creating a Zero model and migrating PostgreSQL, run php artisan zero:dev. Restart Vite to load .env.');

            return self::SUCCESS;
        } catch (LogicException $logicException) {
            $this->error($logicException->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array{string, string, bool} */
    private function appOrigin(bool $herd): array
    {
        $app = rtrim(config()->string('app.url'), '/');
        if ($herd) {
            $host = parse_url($app, PHP_URL_HOST);
            $site = is_string($host) && str_ends_with($host, '.test') ? $host : Str::slug(basename(base_path())).'.test';
            $https = $this->confirm('Use HTTPS with Herd?', true);
            $app = ($https ? 'https://' : 'http://').$site;
        } elseif (in_array($app, ['http://localhost', 'http://127.0.0.1'], true)) {
            $app .= ':8000';
        }

        $answer = $this->ask('Laravel app URL', $app);
        $app = is_string($answer) ? rtrim($answer, '/') : '';
        if (! preg_match('~^https?://([a-zA-Z0-9.\-]+|\[::1\])(?::[0-9]+)?$~D', $app, $parts) || ! filter_var($app, FILTER_VALIDATE_URL)) {
            throw new LogicException('Use an HTTP(S) origin without a path, credentials, query, or fragment.');
        }

        $host = $parts[1];
        if ($herd && (! str_ends_with($host, '.test') || parse_url($app, PHP_URL_PORT) !== null)) {
            throw new LogicException('For the Herd preset, use a .test site URL without a port.');
        }

        return [$app, $host, str_starts_with($app, 'https://')];
    }

    private function publish(string $tag): void
    {
        if ($this->call('vendor:publish', ['--tag' => $tag]) !== self::SUCCESS) {
            throw new LogicException('Could not publish '.$tag.'.');
        }
    }

    private function create(Filesystem $files, string $path, string $content): void
    {
        if ($files->exists($path)) {
            $this->line('Kept '.$path);

            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
        $this->line('Created '.$path);
    }
}
