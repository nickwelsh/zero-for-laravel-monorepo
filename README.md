# Zero for Laravel

Controller-first live queries and mutations for [Rocicorp Zero](https://zero.rocicorp.dev).
Laravel owns validation, binding, authorization, and authoritative writes. Zero owns
replication, subscriptions, optimistic transactions, replay, and mutation outcomes.

**v0.9.0** provides controller-first queries and mutations. Install matching versions of
`nickwelsh/zero-for-laravel`, `@nickwelsh/zero-for-laravel`, and the optional
`@nickwelsh/zero-table`. Both the Zero client
and zero-cache must use **1.11.4**. PHP 8.3+, Laravel 12/13, PostgreSQL 15+ with
logical replication, and React 18/19 are supported targets. Automatic live page
props require Inertia 3 (the React adapter requires `@inertiajs/react` ^3.8.0).

```sh
composer require nickwelsh/zero-for-laravel:^0.9.0
pnpm add @nickwelsh/zero-for-laravel@^0.9.0 @rocicorp/zero@1.11.4
```

The [shared todos example](examples/todos/README.md) is a complete application with
authentication, shared/private lists, controller mutations, and live Inertia props.
The [query design](docs/QUERY_DESIGN.md) and [mutation design](docs/MUTATION_DESIGN.md)
record the architecture and acceptance requirements.

For editable grouped data grids, add `@nickwelsh/zero-table@^0.9.0`. Its
[consumer guide](packages/table/README.md) covers fluent Laravel capabilities,
React hooks and unstyled components, infinite loading, snapshot group counts,
and optional Formbraid 0.3 editors. The table package requires client **^0.9.0**;
ordinary Zero applications do not depend on it.

## Declare live props in controllers

```php
public function index(\Illuminate\Http\Request $request): \Inertia\Response
{
    return \Inertia\Inertia::render('tasks/index', [
        'heading' => 'Tasks',
        'tasks' => Task::zeroQuery()
            ->visibleTo($request->user())
            ->where('done', false)
            ->with('list', fn ($list) => $list->visibleTo($request->user()))
            ->orderBy('created_at', 'desc')
            ->infinite(25),
    ]);
}
```

Use `ZeroForLaravel\Concerns\HasZeroQueries` on participating Eloquent models.
`zeroQuery()` returns a mutable declaration, not an Eloquent query or HTTP row
snapshot. Aliases share changes; clone a builder for an independent branch.
The compiler discovers registered controllers through Ranger, analyzes
FormRequest contracts through Surveyor, and emits coordinated PHP/native ZQL.
Generation never executes an HTTP controller to sample its result.

The Inertia integration removes live result keys from ordinary props before
serialization. Reserved `__zero` metadata carries only query names and normalized
arguments. A directly returned eligible route-bound model becomes a live `one()`
query after normal Laravel binding. Initial missing models still produce Laravel's
normal response. Direct query transforms rerun the required binding/middleware and
FormRequest boundary, not the page controller or its unrelated effects.

Native comparisons, expressions, relationships, ordering, cursors, and cardinality
remain explicit. `infinite()` grows a live window; `paginate()` navigates raw cursors.
Neither promises total counts or immutable historical pages. Plain queries acquire
no hidden limit or ordering.

## Use ordinary Laravel policies

```php
use Illuminate\Auth\Access\Response;
use ZeroForLaravel\Permissions\Casbin;

// Discovered normally by Laravel. No Zero-specific model definition.
public function view(User $user, Task $task): Response
{
    return Casbin::inspect($user, 'view', $task)->asNotFound();
}

Gate::authorize('view', $task); // REST and Inertia show routes.
Task::query()->visibleTo($user)->paginate(); // REST collections.
Task::zeroQuery()->visibleTo($request->user()); // Live collections.
```

Add `HasPolicyVisibility` alongside `HasZeroQueries` to opt into the Eloquent local
scope. Policies choose actions; `config/permissions.php` binds database attributes
and domains to a supported Casbin model. Managed database grants are the authority.
The portable compiler supports ACL, global/domain RBAC and a typed ABAC subset;
unsupported policies fail explicitly. A supported policy `before()` admin override
stays live without bypassing caller filters. There is no auth-dependent global scope.

Models using `HasZeroQueries` are discovered in the application's Composer namespaces. Every protected root,
related selection and existence path needs `visibleTo()` or a supported captured
instance Gate. Declare `#[ZeroPublic]` on an intentionally public model. Creation
defaults use `withAttributes()` and typed application context; explicit creation
attributes override defaults. Defaults are not write authorization or update stamps.
Use checked `#[ZeroScope]` methods for local Zero scopes and `ZeroGlobalScope` for
reusable query constraints. Search uses `zeroSearch()` or explicit registration.
Write authorization stays in FormRequests, middleware and controller Gates.

Captured show Gates also create actor-owned access watches: confirmed access loss
blocks the page before the optional Inertia adapter `access.onRevoked` callback.
Disconnects block pending revalidation; empty collections remain successful.
See [authorization and migration](apps/docs/content/docs/0.x/docs/authorization.mdx)
for setup, supported compiler boundaries and deployment requirements.

Column privacy uses `$visible`, `$hidden`, `ZeroColumns`, `ZeroExclude`, and
`ZeroIncludeColumns`. Generation fails if required keys are excluded. Synchronize a
dedicated PostgreSQL publication; do not let the cache create an unrestricted one.

## Opt controller actions into mutations

```php
use ZeroForLaravel\Attributes\ZeroMutation;

/** @return array{id: string} */
#[ZeroMutation]
public function store(StoreTaskRequest $request): array
{
    $task = Task::create($request->validated());

    return ['id' => $task->id];
}
```

The checked compiler lowers supported writes, branches, transaction reads, shared
helpers, and returns to one operation definition. PHP executes real Eloquent writes;
the browser executes native Zero transactions. Prepared IDs and time are reused.
Actions return void or explicit JSON, never redirects or implicitly serialized models.

`#[ZeroMutation(optimism: 'custom')]` requires a declared client projection.
`optimism: 'disabled'` sends the same typed native intent without speculative writes.
Unsupported generated code fails with its source location; it never silently changes
strategy. Declare structured results with `serverResult: 'array{id: string}'` and,
when different, `clientResult`. Missing bound cache rows are an explicit no-op
prediction, not a local failure that prevents server execution.

`Mutation::reloadForUpdate()` locks/rebinds current state before state-dependent
authorization. `findUnique()`, `insertUnlessExists()`, and `upsertUnique()` use real
unique keys. Conditional insertion preserves an existing opt-out, including a
concurrent insertion. An upsert changes only explicit fields.

Bounded `foreach` accepts validated lists with a `max` of at most 1000 and a required
distinct string `key` per item. UUIDv5 derives per-item IDs from prepared per-site
UUID seeds, independently of order. Nested loops and ULID repeated creation reject.
Nested request schemas and date inputs are encoded before native request creation.

Use `Mutation::server(function () use (...) { ... })` for explicit authoritative
boundaries. External work is never replayed in the browser. Durable notifications
require application-owned outbox rows written on the same connection and an
idempotent worker; an after-commit callback alone is not durable delivery.

## Generate and install the adapters

```sh
php artisan vendor:publish --tag=zero-config
php artisan vendor:publish --tag=permissions-config
php artisan vendor:publish --tag=permissions-migrations
php artisan migrate
# Configure bindings and managed grants, then install private authority projections.
php artisan permissions:rebuild
php artisan zero:generate --react
php artisan zero:sync-publication --dry-run
php artisan zero:sync-publication
```

Generation writes the native schema/metadata, sibling `.queries.ts`, `.mutations.ts`,
optional `.react.ts`, and matching `bootstrap/zero-{queries,mutations}.php` registries.
It also writes one module per controller, with named mutations and native query
factories grouped by method and prop. Types come from these exports, not a global
page namespace; consumers own their page props and presentation types.
The generated file manifest tracks obsolete controller modules for safe removal.
Deploy these together. `zero:generate --check` detects drift. The editable
`zero.config.ts` holds public connection options and is never overwritten.

Applications using Wayfinder `dev-next` can opt into
`ZeroForLaravel\WayfinderServiceProvider` to have **Wayfinder's own page generator**
emit precise live-query prop types. Run `zero:generate` before `wayfinder:generate`.
Only registered Zero props are customized; shared and ordinary props stay with
Wayfinder, without duplicate declarations or global overrides. The controller
module root is configurable with `zero.wayfinder.module` (default `@/zero`).

Install `createInertiaAdapter(bindings)` from the `/inertia` export in your
`createInertiaApp` resolver and call `livePages.wrap(module.default, page)`.
Put the generated `ZeroProvider` in the authenticated persistent layout, outside
the wrapped page. Pass `userID` and `context={{ user_id: user.id }}`. Changing identity
replaces the client and clears identity-scoped results.

```tsx
import { store } from '@/zero/App/Http/Controllers/TaskController';
import type { index } from '@/zero/App/Http/Controllers/TaskController';
import { useControllerMutation, type QueryData } from '@nickwelsh/zero-for-laravel/react';

export default function Tasks({ tasks }: { tasks: QueryData<typeof index.tasks> }) {
    const create = useControllerMutation(store);
    // Await create(input) for validated authoritative result data.
    return tasks.data.map((task) => <p key={task.id}>{task.title}</p>);
}
```

Rows are plain decoded values. Dates are `Date`, including related rows. Native
cursor values retain storage precision rather than being reconstructed from dates.
`useLiveProps<YourPage>()` exposes injected props to descendants; upstream `usePage()`
continues to describe serializable Inertia props. Live status distinguishes idle,
loading, refreshing, success, stale, and failure. Connection state is separate.

Live props expose `inputs.values`, finite `inputs.options`, `inputs.set(partial)`,
and `inputs.reset()` for their FormRequest fields. Changes validate and resubscribe
without a server visit, resetting pagination. Route bindings and trusted context
are not editable. URL/history synchronization is opt-in; see the
[React guide](apps/docs/content/docs/0.x/docs/react.mdx#editable-live-inputs).

For native reads, call `zero.run(index.tasks(input))` or pass that same request to
Zero's `useQuery`. For the optional decoded-data adapter, call
`useData(index.tasks.live(input))`. Internal `bindings` consume wire arguments from
page metadata. The generated `.react.ts` exports a schema-typed `useZero` hook.
For writes, call `zero.mutate(actions[store.action].prepare(input, routeArguments))`
using `actions` from that same generated provider, then observe `.client` and `.server`.
Outside React, construct Zero with the `mutators` returned by
`createControllerMutationBindings(schema, definitions, projections)` and use its
`actions`. Do not mix bindings from different factory instances.
`InferQueryInput`/`InferQueryResult` describe native calls; `InferMutationInput` and
`InferMutationResult` describe business input and decoded authoritative results.
`InferLiveQueryResult` describes decoded adapter rows (including `Date` values),
whereas native results retain wire values. A committed result decode failure is
not a rolled-back write and must not trigger resubmission.

React 19 apps can use Formbraid **0.3** with generated controller descriptors:
import `store` from `@/formbraid/zero/App/Http/Controllers/TodoListController`
and render `<Form form={store} />`. `zero:formbraid` reuses Formbraid's component
metadata and request generator, attaching native Zero submission callbacks.
Formbraid owns draft state; success waits for authoritative acceptance. See the
[form guide](apps/docs/content/docs/0.x/docs/mutations.mdx#formbraid-03).

Generated creates use **prepared fields**: UUIDs/ULIDs inferred from Eloquent traits,
plus timestamps inferred from the model's timestamp settings. The client captures
them before enqueueing, separately from form input, and replay/server execution reuse
them. Override `uuid`, `ulid`, or `now` through `createZeroBindings(preparation)`
(`createZeroBindings(projections, preparation)` for custom optimism), or the hook's
third argument. Overrides run before native mutation creation, never during replay.

## Configure the trusted cache boundary

Laravel needs `ZERO_APP_ID`, matching `ZERO_UPSTREAM_SCHEMA` (normally `<app-id>_0`),
`ZERO_PUBLICATION`, and a private `ZERO_API_KEY`. The cache needs that same identity,
publication, PostgreSQL connection URLs, `ZERO_QUERY_URL`, `ZERO_MUTATE_URL`, and
matching query/mutate API keys. Never put the private cache key in browser code.

For sessions, use `zero.middleware = ['web', 'auth']`, forward cookies on both cache
callbacks, exclude only those callback paths from CSRF validation, and use compatible
app/cache cookie origins. The private cache key and end-user session are both required.
Controller route middleware/binding still runs for registered actions. See the example
for concrete environment configuration and startup commands.

`zero:dev`, `zero:doctor`, `zero:exposure`, and guarded `zero:reset` support local
development. `zero.sync_publication_on_migrate` can synchronize successful migration
batches. Ordinary migrations do not require deleting the cache replica. Full database
resets require stopping the cache and rebuilding its disposable replica.

## Development and verification

```sh
composer --working-dir=packages/laravel install
composer --working-dir=examples/todos install
pnpm install --frozen-lockfile
php examples/todos/artisan wayfinder:generate --fresh
pnpm --filter @nickwelsh/zero-for-laravel build
pnpm check
ZERO_TEST_PG_DATABASE=laravel_zero_test pnpm php:test
pnpm test:integration
```

PostgreSQL tests require a disposable test database. The native integration suite
starts a real cache and tests replication, optimistic reconciliation, authorization,
identity changes, and persisted-result recovery after a deliberately lost response.
The example and its tools require PHP 8.4.1+; use PHP 8.5 for the full workspace.
See [workspace tooling](docs/tooling.md) for database setup, supervised orb services,
API regeneration, and release procedures, and [contract details](docs/model-api.md)
for the public runtime boundary.

MIT licensed.
