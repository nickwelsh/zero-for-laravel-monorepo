<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use ZeroForLaravel\Inertia\ZeroInertiaServiceProvider;

return [
    ZeroInertiaServiceProvider::class,
    AppServiceProvider::class,
    FortifyServiceProvider::class,
];
