<?php

declare(strict_types=1);

namespace ZeroForLaravel\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\DevCommands;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Laravel\Prompts\Elements\Element;
use Laravel\Wayfinder\Converters\InertiaData;
use LogicException;
use PDOException;
use Symfony\Component\Process\ExecutableFinder;
use ZeroForLaravel\Development\FrontendDependencies;
use ZeroForLaravel\Development\InertiaEntryPoint;
use ZeroForLaravel\Development\LocalEnvironment;
use ZeroForLaravel\Development\SetupEnvironmentFile;
use ZeroForLaravel\Generation\AutoDetectFrontendDriver;
use ZeroForLaravel\Generation\FrontendDriver;
use ZeroForLaravel\Generation\GeneratedLayout;
use ZeroForLaravel\Generation\ReactFrontendDriver;

use function Laravel\Prompts\callout;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\task;
use function Laravel\Prompts\text;
use function Laravel\Prompts\title;
use function Laravel\Prompts\warning;

final class SetupCommand extends Command
{
    protected $signature = 'zero:setup';

    protected $description = 'Configure Zero, local development tools, and generated contracts';

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

            try {
                $connection = $local->connection();
                $version = $connection->getDriverName() === 'pgsql' ? $connection->scalar('SHOW server_version_num', [], false) : null;
            } catch (PDOException) {
                throw new LogicException('Could not connect to PostgreSQL. Check the DB_* settings in .env and rerun zero:setup.');
            }

            if (! is_numeric($version) || (int) $version < 150000) {
                throw new LogicException('Zero requires PostgreSQL 15 or later. Set DB_CONNECTION=pgsql and the connection details in .env before setup.');
            }

            $frontend = $frontend instanceof AutoDetectFrontendDriver ? $frontend->detect() : $frontend;
            $interactive = $this->input->isInteractive() && defined('STDIN') && stream_isatty(STDIN);
            if ($interactive) {
                title('Zero for Laravel · Setup');
            }

            info($frontend instanceof ReactFrontendDriver ? 'Detected React.' : ($frontend instanceof FrontendDriver ? 'Using configured frontend driver.' : 'No frontend detected.'));
            $available = [];
            if (class_exists(\Inertia\ServiceProvider::class)) {
                $available['inertia'] = 'Inertia live page props';
            }

            if (class_exists(InertiaData::class)) {
                $available['wayfinder'] = 'Wayfinder page prop types';
            }

            $features = $available === [] ? [] : multiselect('Which integrations would you like to enable?', $available, default: array_keys($available), hint: 'Installed integrations are selected. Press Space to toggle.');
            $inertia = in_array('inertia', $features, true);
            $wayfinder = in_array('wayfinder', $features, true);
            $session = select('How would you like to authenticate?', [
                'session' => 'Laravel session cookies',
                'token' => 'Bearer token (your Laravel guard)',
            ], default: $inertia ? 'session' : 'token') === 'session';
            $extras = multiselect('Which files would you like to publish?', [
                'context' => 'Context classes for fields beyond user_id',
                'permissions' => 'Managed permissions config and migrations',
                'config' => 'Editable config/zero.php for custom overrides',
            ], hint: 'Leave empty to use package defaults.');
            $layout = new GeneratedLayout(config()->string('zero.generation.output'));
            $directory = $layout->editableRoot();
            $entry = resource_path('js/app.tsx');
            $originalEntry = null;
            $updatedEntry = null;
            if ($inertia && $frontend instanceof ReactFrontendDriver && $directory === resource_path('js/zero') && $files->isFile($entry) && ! is_link($entry) && $files->isDirectory(resource_path('js/pages'))) {
                $originalEntry = $files->get($entry);
                $updatedEntry = InertiaEntryPoint::withResolver($originalEntry);
                if ($updatedEntry !== null && ! confirm('Add the live page resolver to resources/js/app.tsx?')) {
                    $updatedEntry = null;
                }
            }

            $install = $frontend instanceof ReactFrontendDriver ? FrontendDependencies::installCommand($files) : [];
            $installNow = $install !== null && $install !== [] && confirm('Install frontend dependencies with '.$install[0].'?', hint: implode(' ', $install));
            $herd = (new ExecutableFinder)->find('herd') !== null && confirm('Configure the site and cache proxy with Laravel Herd?');
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

            $summary = ['Laravel' => $app, 'Zero cache' => $cache, 'Authentication' => $session ? 'Session cookies' : 'Bearer token'];
            if ($session) {
                $summary['Session domain'] = $herd ? '.'.$host : 'Host only';
            }

            if ($herd) {
                $summary['Herd'] = $secure ? 'Link site, secure it, and create HTTPS proxy' : 'Link site and create HTTP proxy';
            }

            callout('Local connection', [Element::keyValueList($summary)]);
            if (! $herd && $secure) {
                warning("Forward /sync/ WebSockets to http://127.0.0.1:{$port} in your HTTPS proxy.");
            }

            $private = new SetupEnvironmentFile($files, $path);
            $example = new SetupEnvironmentFile($files, base_path('.env.example'));
            $private->stage($settings, true);
            $example->stage($settings, false);

            if (! confirm('Apply these settings and generate Zero contracts?', default: $interactive)) {
                return self::SUCCESS;
            }

            $next = task('Apply local settings', function () use ($herd, $host, $secure, $port, $private, $example, $extras, $layout, $files, $frontend, $inertia, $directory, $updatedEntry, $entry, $originalEntry): array {
                $next = [];
                if ($herd) {
                    $site = substr($host, 0, -5);
                    $this->runLocal(['herd', 'link', $site]);
                    if ($secure) {
                        $this->runLocal(['herd', 'secure', $site]);
                    }

                    $this->runLocal(['herd', 'proxy', 'zero.'.$site, 'http://127.0.0.1:'.$port, ...($secure ? ['--secure'] : [])]);
                    if ($secure && ! config('zero.dev.ca')) {
                        $ca = getenv('HOME').'/Library/Application Support/Herd/config/valet/CA/LaravelValetCASelfSigned.pem';
                        if (is_readable($ca)) {
                            $private->stage(['ZERO_CA_CERT' => '"'.addcslashes($ca, '\\"$').'"'], true);
                        } else {
                            $next[] = 'If Node rejects the local certificate, set ZERO_CA_CERT in .env to the Herd CA path.';
                        }
                    }
                }

                $private->save(true);
                $example->save(false);

                if (in_array('config', $extras, true) || in_array('context', $extras, true)) {
                    $this->publish('zero-config');
                }

                if (in_array('context', $extras, true)) {
                    $this->publish('zero-context');
                    $config = config_path('zero.php');
                    $original = $files->get($config);
                    if ($original === $files->get(__DIR__.'/../../config/zero.php')) {
                        $files->replace($config, strtr($original, [
                            'use ZeroForLaravel\\Context\\ZeroContext;' => 'use App\\Enums\\ZeroContext;',
                            'use ZeroForLaravel\\Context\\ResolveZeroContext;' => 'use App\\Zero\\ResolveZeroContext;',
                        ]));
                    } else {
                        $next[] = 'In config/zero.php, point context to App\\Enums\\ZeroContext::class and context_resolver to App\\Zero\\ResolveZeroContext::class.';
                    }
                }

                if (in_array('permissions', $extras, true)) {
                    $this->publish('permissions-config');
                    // Published migration timestamps can differ. Match by migration name on reruns.
                    foreach ($files->files(__DIR__.'/../../database/migrations') as $migration) {
                        $name = substr($migration->getFilename(), 18);
                        if ($files->glob(database_path('migrations/*_'.$name)) === []) {
                            $files->ensureDirectoryExists(database_path('migrations'));
                            $files->copy($migration->getPathname(), database_path('migrations/'.$migration->getFilename()));
                        }
                    }

                    $next[] = 'Configure permission bindings in config/permissions.php before migrating.';
                }

                $layout->writeIgnoreRules($files);
                $configuration = $frontend?->configuration();
                if ($configuration !== null) {
                    $this->create($files, $directory.'/zero.config.ts', $configuration);
                }

                if ($frontend instanceof ReactFrontendDriver) {
                    $next[] = 'Wrap your signed-in layout in ZeroProvider.';
                    if ($inertia) {
                        $this->create($files, $directory.'/inertia.ts', "import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';\nimport { bindings, navigation } from '{$layout->queryImport()}';\n\nexport const livePages = createInertiaAdapter(bindings, { navigation });\nexport const Link = livePages.Link;\n");
                        if ($updatedEntry !== null && $files->get($entry) === $originalEntry) {
                            $files->replace($entry, $updatedEntry);
                        } elseif (! str_contains($originalEntry ?? '', 'livePages.wrap(')) {
                            $next[] = 'In your Inertia resolver, return livePages.wrap(module.default, page) (@inertiajs/react ^3.8).';
                        }
                    }
                }

                return $next;
            }, limit: 1, keepSummary: true);

            if ($installNow && $install !== null) {
                task('Install frontend dependencies', function () use ($install): void {
                    $this->runLocal($install);
                }, limit: 1, keepSummary: true);
            } elseif ($install !== []) {
                array_unshift($next, $install === null ? 'Use your package manager to install @nickwelsh/zero-for-laravel, @rocicorp/zero@1.11.4 and zod.' : 'Run '.implode(' ', $install).'.');
            }

            // Boot Artisan again: the running app still has the old middleware and context configuration.
            task('Generate Zero contracts', function () use ($settings): void {
                $command = [PHP_BINARY, 'artisan', 'zero:generate', '--no-interaction'];
                if (is_string($env = $this->option('env'))) {
                    $command[] = '--env='.$env;
                }

                $result = Process::path(base_path())->forever()->env(array_fill_keys([...array_keys($settings), 'ZERO_API_KEY'], false))->run($command);
                if ($result->failed()) {
                    throw new LogicException('Settings saved, but generation failed. Run php artisan zero:generate to see the error and retry.');
                }
            }, limit: 1, keepSummary: true);

            if (! $session) {
                $next[] = 'Pass the user’s bearer token as auth to Zero and configure the matching Laravel guard for the callback routes.';
            }

            $next[] = class_exists(DevCommands::class)
                ? 'After migrating PostgreSQL and adding a Zero model, start or restart php artisan dev. It starts Zero too.'
                : 'After migrating PostgreSQL and adding a Zero model, run php artisan zero:dev in a separate terminal. Restart Vite to load .env.';
            callout('Next steps', [Element::bulletedList($next)]);

            return self::SUCCESS;
        } catch (LogicException $logicException) {
            error($logicException->getMessage());

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
            $https = confirm('Use HTTPS with Herd?');
            $app = ($https ? 'https://' : 'http://').$site;
        } elseif (in_array($app, ['http://localhost', 'http://127.0.0.1'], true)) {
            $app .= ':8000';
        }

        $app = rtrim(text('Laravel app URL', default: $app, required: true), '/');
        if (! preg_match('~^https?://([a-zA-Z0-9.\-]+|\[::1\])(?::[0-9]+)?$~D', $app, $parts) || ! filter_var($app, FILTER_VALIDATE_URL)) {
            throw new LogicException('Use an HTTP(S) origin without a path, credentials, query, or fragment.');
        }

        $host = $parts[1];
        if ($herd && (! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.-]*\.test$/D', $host) || parse_url($app, PHP_URL_PORT) !== null)) {
            throw new LogicException('For the Herd preset, use a .test site URL without a port.');
        }

        return [$app, $host, str_starts_with($app, 'https://')];
    }

    /** @param list<string> $command */
    private function runLocal(array $command): void
    {
        if (Process::path(base_path())->forever()->run($command)->failed()) {
            throw new LogicException('Setup stopped at `'.implode(' ', $command).'`. Run it in your project directory for details, then rerun zero:setup.');
        }
    }

    private function publish(string $tag): void
    {
        if ($this->callSilent('vendor:publish', ['--tag' => $tag]) !== self::SUCCESS) {
            throw new LogicException('Could not publish '.$tag.'.');
        }
    }

    private function create(Filesystem $files, string $path, string $content): void
    {
        if ($files->exists($path)) {
            return;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
    }
}
