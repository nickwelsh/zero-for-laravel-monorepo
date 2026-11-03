<?php

declare(strict_types=1);

use App\Enums\ZeroContext;
use App\Models\Todo;
use App\Models\TodoList;
use App\Zero\ResolveZeroContext;

return [
    // Only these domain models may enter Zero's browser schema.
    'models' => [TodoList::class, Todo::class],
    'public_models' => [],
    'output' => resource_path('js/zero/generated.ts'),
    'react' => true,
    // Synchronize after successful, non-pretend PostgreSQL migration batches.
    'sync_publication_on_migrate' => true,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),
    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),
    'route_prefix' => 'zero',
    'middleware' => ['web', 'auth'],
    'context' => ZeroContext::class,
    'context_resolver' => ResolveZeroContext::class,
];
