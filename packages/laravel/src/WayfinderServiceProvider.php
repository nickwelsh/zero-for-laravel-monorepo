<?php

declare(strict_types=1);

namespace ZeroForLaravel;

use Illuminate\Support\ServiceProvider;
use Laravel\Wayfinder\Converters\InertiaData;
use ZeroForLaravel\Generation\WayfinderInertiaData;

/** Opt-in integration; Wayfinder remains the sole owner of its page declarations. */
final class WayfinderServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->runningInConsole() || ! class_exists(InertiaData::class)) {
            return;
        }

        $this->app->bind(InertiaData::class, WayfinderInertiaData::class);
    }
}
