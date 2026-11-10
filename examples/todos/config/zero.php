<?php

declare(strict_types=1);

use App\Enums\ZeroContext;
use App\Zero\ResolveZeroContext;

return [
    /*
    |--------------------------------------------------------------------------
    | Formbraid Integration
    |--------------------------------------------------------------------------
    |
    | Generate the demo's forms with its Zero contracts and at zero:dev startup.
    | Vite produces component metadata before invoking the generate command.
    | Form descriptors use the standard generated controller bindings.
    |
    */

    'formbraid' => [
        'enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Publication
    |--------------------------------------------------------------------------
    |
    | Keep the demo's publication in sync after PostgreSQL migrations succeed.
    | Models and tables are discovered from the application's namespaces.
    | Other defaults come from the package's published configuration.
    |
    */

    'sync_publication_on_migrate' => true,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),
    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Browser Authentication
    |--------------------------------------------------------------------------
    |
    | This application uses sessions to authenticate Zero's callback endpoints.
    | The web middleware restores the session before auth checks the user.
    | The cache API key is separate and never reaches the browser.
    |
    */

    'route_prefix' => 'zero',
    'middleware' => ['web', 'auth'],
    'context' => ZeroContext::class,
    'context_resolver' => ResolveZeroContext::class,
];
