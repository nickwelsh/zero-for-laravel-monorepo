<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Support\ServiceProvider;

final class PermissionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/permissions.php', 'permissions');
        $this->app->singleton(PermissionManager::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([RebuildPermissionsCommand::class]);
            $this->publishes([
                __DIR__.'/../../config/permissions.php' => config_path('permissions.php'),
                __DIR__.'/../../config/permissions' => config_path('permissions'),
            ], 'permissions-config');
            $this->publishesMigrations([
                __DIR__.'/../../database/migrations' => database_path('migrations'),
            ], 'permissions-migrations');
        }
    }
}
