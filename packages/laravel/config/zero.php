<?php

declare(strict_types=1);

use ZeroForLaravel\Http\ContextResolver;

return [
    // Empty discovers models with Zero attributes or a zero() definition under app/Models.
    'models' => [],
    // Explicit named business actions. Values extend Domain\DomainMutation.
    'domain_mutations' => [],
    // When models is empty, only explicitly opted-in models in these PSR-4 roots are discovered.
    'discovery' => [], // Empty retains app/Models and the application's namespace.
    'output' => resource_path('js/zero/generated.ts'),
    // TypeScript module root for the opt-in WayfinderServiceProvider (not a filesystem path).
    'wayfinder_import' => '@/zero',
    // Deploy this registration together with the sibling generated.queries.ts module.
    'controller_output' => base_path('bootstrap/zero-queries.php'),
    'mutation_output' => base_path('bootstrap/zero-mutations.php'),
    // Optional React wrapper. Its editable sibling config holds public connection options.
    'react' => false,
    // Synchronize after successful, non-pretend PostgreSQL migration batches.
    'sync_publication_on_migrate' => false,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),
    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),
    'route_prefix' => 'zero',
    'middleware' => ['api', 'auth'],
    // Bind this class (or replace it) to add trusted tenant context.
    'context_resolver' => ContextResolver::class,
    'dev' => [
        'port' => (int) env('ZERO_PORT', 4848),
        'replica' => storage_path('app/zero/replica.db'),
        'node' => 'node',
        'ca' => env('ZERO_CA_CERT'),
    ],
];
