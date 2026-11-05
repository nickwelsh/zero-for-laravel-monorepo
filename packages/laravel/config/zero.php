<?php

declare(strict_types=1);
use App\Enums\ZeroContext;
use App\Zero\ResolveZeroContext;

return [
    // Empty discovers models using HasZeroQueries under app/Models.
    'models' => [],
    'discovery' => [],
    // Explicit table aliases; no table definitions are discovered automatically.
    'tables' => [],
    // Separate publication opt-in from visibility. Missing policy bindings never mean public reads.
    'public_models' => [],
    'output' => resource_path('js/zero/generated.ts'),
    // Generated validators: zod (default) or effect (requires Effect 4 in the application).
    'schema_driver' => 'zod',
    // TypeScript module root for the opt-in WayfinderServiceProvider (not a filesystem path).
    'wayfinder_import' => '@/zero',
    // Deploy this registration together with the sibling generated.queries.ts module.
    'controller_output' => base_path('bootstrap/zero-queries.php'),
    'mutation_output' => base_path('bootstrap/zero-mutations.php'),
    // Optional React wrapper. Its editable sibling config holds public connection options.
    'react' => false,
    // Optional Formbraid producer; component metadata/configuration belong to formbraid/laravel.
    'formbraid_output' => 'resources/js/formbraid/zero',
    // Override for application-owned bindings (for example, custom optimism).
    'formbraid_runtime' => null,
    // Synchronize after successful, non-pretend PostgreSQL migration batches.
    'sync_publication_on_migrate' => false,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),
    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),
    'route_prefix' => 'zero',
    'middleware' => ['api', 'auth'],
    // Application-owned enum and attributed resolver methods. Values are never sampled during generation.
    'context' => ZeroContext::class,
    'context_resolver' => ResolveZeroContext::class,
    'dev' => [
        'port' => (int) env('ZERO_PORT', 4848),
        'replica' => storage_path('app/zero/replica.db'),
        'node' => 'node',
        'ca' => env('ZERO_CA_CERT'),
    ],
];
