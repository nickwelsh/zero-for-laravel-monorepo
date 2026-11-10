<?php

declare(strict_types=1);

namespace ZeroForLaravel;

use Closure;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ranger\RangerServiceProvider;
use Laravel\Surveyor\SurveyorServiceProvider;
use LogicException;
use ReflectionMethod;
use Typesense\Client;
use ZeroForLaravel\Console\DevCommand;
use ZeroForLaravel\Console\DoctorCommand;
use ZeroForLaravel\Console\ExposureCommand;
use ZeroForLaravel\Console\FormbraidCommand;
use ZeroForLaravel\Console\GenerateCommand;
use ZeroForLaravel\Console\ResetCommand;
use ZeroForLaravel\Console\SyncPublicationCommand;
use ZeroForLaravel\Http\AuthenticateZeroCache;
use ZeroForLaravel\Http\ZeroController;
use ZeroForLaravel\Metadata\ModelDiscovery;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Mutation\MutationProcessor;
use ZeroForLaravel\Publication\PublicationManager;

final class ZeroServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->register(SurveyorServiceProvider::class);
        $this->app->register(RangerServiceProvider::class);
        $this->app->register(Permissions\PermissionServiceProvider::class);

        $middleware = config('zero.middleware');
        $this->replaceConfigRecursivelyFrom(__DIR__.'/../config/zero.php', 'zero');
        if ($middleware !== null) {
            // Middleware is an ordered list to replace, not a group of defaults.
            config()->set('zero.middleware', $middleware);
        }

        $this->app->singletonIf(Generation\Mutations\MutationSchemaDriver::class, function (Application $app): Generation\Mutations\MutationSchemaDriver {
            $class = config()->string('zero.generation.schema');
            $driver = class_exists($class) ? $app->make($class) : null;
            if (! $driver instanceof Generation\Mutations\MutationSchemaDriver) {
                throw new LogicException('zero.generation.schema must implement '.Generation\Mutations\MutationSchemaDriver::class.'.');
            }

            return $driver;
        });
        $this->app->singletonIf(Generation\SchemaDriver::class, fn (Application $app): Generation\SchemaDriver => $app->make(Generation\Mutations\MutationSchemaDriver::class));
        $this->app->singletonIf(Generation\FrontendDriver::class, function (Application $app): Generation\FrontendDriver {
            $class = config()->string('zero.frontend.driver');
            $driver = class_exists($class) ? $app->make($class) : null;
            if (! $driver instanceof Generation\FrontendDriver) {
                throw new LogicException('zero.frontend.driver must implement '.Generation\FrontendDriver::class.'.');
            }

            return $driver;
        });
        $this->app->singleton(Query\RegisteredQueries::class, fn (): Query\RegisteredQueries => Query\RegisteredQueries::load(config()->string('zero.generation.queries')));
        $this->app->singleton(function (): Mutation\ControllerMutationRegistry {
            $path = config()->string('zero.generation.mutations');
            if (! is_file($path)) {
                throw new LogicException('Missing controller mutation registrations. Run zero:generate.');
            }

            $factory = require $path;
            if (! $factory instanceof Closure) {
                throw new LogicException('Invalid controller mutation registrations. Run zero:generate.');
            }

            $registry = $factory($this->app->make(ModelRegistry::class), $this->app->make(Http\ControllerRouteDispatcher::class));
            if (! $registry instanceof Mutation\ControllerMutationRegistry) {
                throw new LogicException('Invalid controller mutation registry. Run zero:generate.');
            }

            return $registry;
        });
        if (class_exists(Client::class)) {
            $this->app->singletonIf(Client::class, fn (): Client => new Client(config()->array('scout.typesense.client-settings')));
        }

        $this->app->singleton(function (Application $app): ModelRegistry {
            $classes = $app->make(ModelDiscovery::class)->registered();

            return new ModelRegistry($classes);
        });
        $this->app->singleton(MutationProcessor::class, fn (Application $app): MutationProcessor => new MutationProcessor(
            $app->make(ModelRegistry::class),
            config()->string('zero.app_id'), config()->string('zero.upstream_schema'),
        ));
    }

    public function boot(): void
    {
        Event::listen(RouteMatched::class, static function (RouteMatched $event): void {
            if ($event->request->attributes->get('zero.controller.dispatch') === true) {
                return;
            }

            $class = $event->route->getControllerClass();
            $method = $event->route->getActionMethod();
            if ($class !== null && method_exists($class, $method) && (new ReflectionMethod($class, $method))->getAttributes(Attributes\ZeroMutation::class) !== []) {
                abort(405, 'Submit this action through its generated Zero mutation binding.');
            }
        });
        $this->callAfterResolving(Kernel::class, static function (\Illuminate\Foundation\Http\Kernel $kernel): void {
            // Capture original transport cookies before the web group decrypts them.
            $kernel->prependToMiddlewarePriority(AuthenticateZeroCache::class);
            $kernel->prependToMiddlewarePriority(Http\CaptureTransportCookies::class);
        });
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/zero.php' => config_path('zero.php')], 'zero-config');
            $this->commands([GenerateCommand::class, FormbraidCommand::class, SyncPublicationCommand::class, DevCommand::class, DoctorCommand::class, ResetCommand::class, ExposureCommand::class]);
            Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
                if ($event->method !== 'up' || ($event->options['pretend'] ?? false) || ! config()->boolean('zero.sync_publication_on_migrate')) {
                    return;
                }

                $migrator = $this->app->make(Migrator::class);
                $connection = $migrator->resolveConnection($migrator->getConnection());
                if ($connection->getDriverName() !== 'pgsql') {
                    return;
                }

                // Check connection identity without resolving final column metadata.
                $classes = $this->app->make(ModelDiscovery::class)->registered();
                if ($classes === [] || (new $classes[0])->getConnection()->getName() !== $connection->getName()) {
                    return;
                }

                // Migrations may have changed columns on an already-resolved registry.
                $this->app->forgetInstance(ModelRegistry::class);
                $registry = $this->app->make(ModelRegistry::class);
                $this->app->make(Generation\ControllerDiscovery::class)->queries();

                (new PublicationManager($registry))->sync(config()->string('zero.publication'));
            });
        }

        if (! $this->app->routesAreCached()) {
            $middleware = config()->array('zero.middleware');
            $names = [];
            foreach ($middleware as $name) {
                if (! is_string($name)) {
                    throw new LogicException('Zero middleware must contain middleware names.');
                }

                $names[] = $name;
            }

            Route::prefix(config()->string('zero.route_prefix'))->middleware(array_merge([AuthenticateZeroCache::class], $names))->group(function (): void {
                Route::post('query', [ZeroController::class, 'query'])->name('zero.query');
                Route::post('mutate', [ZeroController::class, 'mutate'])->name('zero.mutate');
            });
            Route::prefix(config()->string('zero.route_prefix'))->middleware(array_values(array_unique(['web', 'auth', ...$names])))->group(function (): void {
                Route::post('tables/{table}/metadata', Http\TableMetadataController::class)->name('zero.tables.metadata');
            });

            if (config()->array('permissions.predictions.abilities') !== []) {
                Route::prefix(config()->string('zero.route_prefix'))->middleware([Http\CaptureTransportCookies::class, ...config()->array('permissions.predictions.middleware', ['web', 'auth'])])->group(function (): void {
                    Route::get('permissions', Permissions\PredictionController::class)->name('zero.permissions');
                });
            }
        }
    }
}
