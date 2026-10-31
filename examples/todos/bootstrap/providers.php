<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;
use ZeroForLaravel\WayfinderServiceProvider;

return [
    ZeroInertiaServiceProvider::class,
    WayfinderServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
