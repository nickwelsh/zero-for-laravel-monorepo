<?php

declare(strict_types=1);

use App\Enums\ZeroContext;
use App\Zero\ResolveZeroContext;
use ZeroForLaravel\Generation\AutoDetectFrontendDriver;
use ZeroForLaravel\Generation\Mutations\ZodMutationSchemaDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Application Discovery
    |--------------------------------------------------------------------------
    |
    | Zero discovers models using HasZeroQueries in your application's Composer
    | PSR-4 namespaces, including modules. Table classes ending in Table with a
    | public definition() returning Table are discovered in those namespaces.
    | Public instance methods returning ZeroQuery are standalone query exports;
    | they need no routes. This discovery selection includes their classes too.
    |
    | Leave discovery set to auto, or replace it with a list of class names and
    | globs. Globs may match namespaces or PHP paths relative to your project.
    | Use ** in file paths to include nested directories; [] selects none.
    |
    | Discovery tells Zero which database tables to copy, not who may read them.
    | Private models need a policy and a permissions.resources binding.
    | Add #[ZeroPublic] only for rows that every user may read.
    |
    */

    'discovery' => 'auto',

    /*
    |--------------------------------------------------------------------------
    | Generated Contracts
    |--------------------------------------------------------------------------
    |
    | These paths select where zero:generate writes the browser schema and the
    | PHP query and mutation registrations. Deploy these files together so
    | Laravel and the browser agree on the current application contracts.
    |
    | The schema class generates validators for query and mutation inputs. Use a
    | MutationSchemaDriver implementation of your own, or EffectSchemaDriver
    | for applications using Effect 4 instead of the default Zod validators.
    |
    */

    'generation' => [
        'output' => resource_path('js/zero/generated.ts'),
        'queries' => base_path('bootstrap/zero-queries.php'),
        'mutations' => base_path('bootstrap/zero-mutations.php'),
        'schema' => ZodMutationSchemaDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Integration
    |--------------------------------------------------------------------------
    |
    | The default driver detects your frontend framework from package.json.
    | React is currently supported. For another integration, supply a
    | FrontendDriver class; Laravel resolves its dependencies.
    |
    | The React driver creates an editable zero.config.ts beside the schema.
    | Set the public cache URL there. Generation preserves your changes.
    | Private credentials such as the API key must never go in it.
    |
    */

    'frontend' => [
        'driver' => AutoDetectFrontendDriver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Wayfinder Imports
    |--------------------------------------------------------------------------
    |
    | Zero's WayfinderServiceProvider uses this TypeScript import root when it
    | generates Inertia page types that refer to Zero's controller modules.
    | Use an alias such as @/zero, not a directory on the server.
    |
    */

    'wayfinder' => [
        'module' => '@/zero',
    ],

    /*
    |--------------------------------------------------------------------------
    | Formbraid Integration
    |--------------------------------------------------------------------------
    |
    | Enable Formbraid to include React form descriptors in zero:generate and
    | zero:dev startup. The --check option checks forms without writing them.
    | The zero:formbraid command also works when this setting is disabled.
    |
    | Output is relative to your project. Configure Formbraid's field components
    | and metadata in config/formbraid.php, then let its Vite plugin produce
    | the component metadata before running either generation command.
    |
    | Forms import controllerRuntime to submit through your Zero client. Leave
    | runtime null to use the generated React module. With custom optimistic
    | code, set it to the project-relative path of a TypeScript module
    | that exports controllerRuntime from createZeroBindings(). Use
    | the same bindings for your provider and generated forms.
    |
    */

    'formbraid' => [
        'enabled' => false,
        'output' => 'resources/js/formbraid/zero',
        'runtime' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | PostgreSQL Publication
    |--------------------------------------------------------------------------
    |
    | Zero manages a dedicated publication for the discovered model tables.
    | Enable synchronization to update it after PostgreSQL migrations.
    | Rollbacks, pretend runs, and other connections are skipped.
    |
    */

    'sync_publication_on_migrate' => false,
    'publication' => env('ZERO_PUBLICATION', 'laravel_zero'),

    /*
    |--------------------------------------------------------------------------
    | Cache Connection
    |--------------------------------------------------------------------------
    |
    | These values identify the application to Zero cache. The upstream schema
    | stores bookkeeping and must match the schema used by the cache server.
    | The API key authenticates callbacks and must remain on the server.
    |
    */

    'app_id' => env('ZERO_APP_ID', 'zero'),
    'upstream_schema' => env('ZERO_UPSTREAM_SCHEMA', 'zero_0'),
    'api_key' => env('ZERO_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | HTTP Endpoints
    |--------------------------------------------------------------------------
    |
    | Query and mutation callbacks are registered beneath this route prefix. The
    | middleware authenticates the application user separately from the cache.
    | Use web and auth middleware when your application relies on sessions.
    |
    */

    'route_prefix' => 'zero',
    'middleware' => ['api', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Request Context
    |--------------------------------------------------------------------------
    |
    | The context enum declares values available to Zero queries and mutations.
    | The resolver supplies one attributed method per case using the request.
    | Generation reads their types without running the resolver methods.
    |
    */

    'context' => ZeroContext::class,
    'context_resolver' => ResolveZeroContext::class,

    /*
    |--------------------------------------------------------------------------
    | Local Development
    |--------------------------------------------------------------------------
    |
    | The zero:dev command uses these settings to start a local Zero cache.
    | The replica is a local database; node selects the executable path.
    | Set ca to a certificate file when your database requires one.
    |
    */

    'dev' => [
        'port' => (int) env('ZERO_PORT', 4848),
        'replica' => storage_path('app/zero/replica.db'),
        'node' => 'node',
        'ca' => env('ZERO_CA_CERT'),
    ],
];
