# Zero for Laravel

Laravel-owned model contracts, automatic queries, and optimistic CRUD for
[Rocicorp Zero](https://zero.rocicorp.dev). Declare the allowed interface in PHP,
generate a native Zero schema and typed TypeScript models, and subscribe from React.
Laravel authenticates reads and authorizes writes; Zero owns replication, local
subscriptions, optimistic replay, and reconciliation.

This monorepo contains `nickwelsh/zero-for-laravel` in `packages/laravel` and
`@nickwelsh/zero-for-laravel` in `packages/client`. The client has a framework-independent
core and a separate `/react` export. Both target **Zero 1.10.0**.

## Requirements and installation

- PHP 8.3+, Laravel 12 or 13, and the PDO PostgreSQL extension.
- PostgreSQL 15+ with logical replication configured for Zero.
- Zero cache 1.10.0, the matching npm client, and React 18 or 19 for React bindings.

Install the PHP package from Packagist and the JavaScript client from npm in
your Laravel application, then publish the configuration:

```sh
composer require nickwelsh/zero-for-laravel
pnpm add @nickwelsh/zero-for-laravel
pnpm add --save-exact @rocicorp/zero@1.10.0
php artisan vendor:publish --tag=zero-config
```

These commands install the current stable Zero for Laravel release, **v0.4.0**,
without development stability flags or an explicit version suffix. Keep the PHP
and JavaScript packages on matching release versions. The Zero client and cache
must both use **1.10.0**. React bindings require React 18 or 19 in your application;
the core client can be used without React.

Laravel discovers the service provider automatically. The default model discovery
scans classes with Zero attributes or a `zero()` definition under `app/Models`. Alternatively, list every participating
model in `config/zero.php`, including relationship targets:

```php
'models' => [App\Models\Task::class, App\Models\TaskList::class],
```

## Define a model

Use a UUID or ULID primary key for optimistic creates. This example assumes a
`tasks` table with a UUID `id`, `user_id`, nullable UUID `list_id`, `title`, a
boolean `done` defaulting to false, nullable `completed_at`, timestamps, and soft
deletes. Match `user_id` to the authenticated user's actual key type.

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use ZeroForLaravel\Attributes\{ZeroMutate, ZeroQuery};
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\{AllowedFilter, ZeroDefinition};

#[ZeroQuery(['all', 'one'])]
#[ZeroMutate(['create', 'update', 'destroy', 'restore', 'force_delete'])]
class Task extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = ['user_id', 'list_id', 'title', 'done', 'completed_at'];

    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()
            ->authorize('user_id', '=', ContextField::UserId)
            ->fillFromContext('user_id', ContextField::UserId)
            ->allowedFilters([
                'title', // String shorthand means escaped substring matching.
                AllowedFilter::exact('done'),
                AllowedFilter::exact('completed_at'),
                AllowedFilter::belongsTo('list'),
                AllowedFilter::trashed(),
            ])
            ->allowedIncludes(['list'])
            ->allowedSorts(['title', 'created_at'])
            ->defaultSort('-created_at')
            ->limits(default: 50, max: 1000);
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }
}
```

The related model declares its own read visibility. It can participate in an
include without exposing top-level queries or mutations:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Definition\ZeroDefinition;
use ZeroForLaravel\ContextField;

class TaskList extends Model
{
    protected $table = 'lists';
    public $timestamps = false;

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorize('user_id', '=', ContextField::UserId);
    }
}
```

Use `->publicReads()` to explicitly allow public reads. Multiple `->authorize()`
constraints combine with AND. The former visibility/fill attributes remain supported for compatibility. Authorization applies to roots, included rows, nested
includes, and relationship filters. Missing trusted context denies access.
`ContextField::TenantId` is also supported; supply it from a custom server context
resolver after verifying tenant membership. Configure that resolver with
`zero.context_resolver`. The browser context supplies local query values and
optimistic fills; Laravel independently resolves authoritative context.

## Visibility through relationships

Use `->authorizeRelation('memberships')` to require at least one related row
that is visible under the related model's own authorization. For a shared list,
configure memberships with `->authorize('user_id', '=', ContextField::UserId)`
and the list with `->authorizeRelation('memberships')`. Todos can then use
`->authorizeRelation('list')` to inherit access to their parent list.

These predicates compile to correlated `EXISTS` queries in both Laravel and the
native client. Multiple scalar and relationship authorization attributes combine
with AND. Authorization relations do not automatically become allowed includes;
declare includes separately. Register every target model. Cycles and authorization
paths deeper than eight relationships fail generation. Write policies and
FormRequests still control mutations and association changes.

See [the authenticated todos example](examples/todos/README.md) for shared lists,
PostgreSQL, and Herd HTTPS setup.

## Policies and validation

Queries and mutations are individually opt-in. Writes require allowing Laravel
policies or gates: `create`, `update`, `delete`, `restore`, and `forceDelete`.
For example:

```php
namespace App\Policies;

use App\Models\{Task, User};

class TaskPolicy
{
    public function create(User $user): bool { return true; }
    public function update(User $user, Task $task): bool
    {
        return $task->user_id === $user->getAuthIdentifier();
    }
    public function delete(User $user, Task $task): bool { return $this->update($user, $task); }
    public function restore(User $user, Task $task): bool { return $this->update($user, $task); }
    public function forceDelete(User $user, Task $task): bool { return $this->update($user, $task); }
}
```

Use Laravel's policy discovery or explicitly register the policy with
`Gate::policy(Task::class, TaskPolicy::class)`. Validate foreign keys and the user's
permission to associate those records in your FormRequest; read visibility alone
does not authorize writing relationship IDs.

Map FormRequests in the definition:

```php
->mutationRequests([
    'create' => \App\Http\Requests\StoreTask::class,
    'update' => \App\Http\Requests\UpdateTask::class,
])
```

Rules must be extractable without runtime request data. Common portable rules
(`required`, `nullable`, `sometimes`, scalar types, `min`, `max`, `in`, date casts,
`uuid`, and `ulid`) become client validators; required rules also refine input
types. Database rules, rule objects, and unsupported rules remain server-only,
with generation diagnostics. Laravel runs the complete FormRequest lifecycle,
including authorization, normalization, and validation hooks. The request sees
the operation's fields and `id`, its authenticated user, and `route('model')` on
the HTTP endpoint. Dates arrive as ISO strings for Laravel validation.

Create inputs require nonnullable writable fields without literal database
defaults. Updates are patches. `->fillFromContext()` declarations fill fields
on create from trusted context and remove them from caller-writable inputs.
Primary keys, server fills, and lifecycle timestamps cannot be patched.
Writes use Eloquent events and authoritative server timestamps; cancelled events
and validation/policy failures become durable application errors.

## Generate contracts and restrict replication

Run these against the same migrated PostgreSQL database used by Zero:

```sh
php artisan zero:generate
php artisan zero:sync-publication --dry-run
php artisan zero:sync-publication
```

To synchronize automatically after successful PostgreSQL migration batches, set
`zero.sync_publication_on_migrate` to `true` in `config/zero.php`. This is enabled
in the todos example and opt-in for the library, since the migration role must
have publication privileges. The hook runs after `up` migrations, including the
batch inside `migrate:fresh` and `migrate:refresh`; it skips rollbacks, pretend runs,
and non-PostgreSQL connections. It rebuilds metadata after schema changes and
keeps the same dedicated publication and column privacy rules. Publication errors
fail the migration command instead of silently leaving replication incomplete.
The manual command remains available for deployments that manage publication
changes separately or need to repair an existing publication without new migrations.

Ordinary migrations do not require deleting Zero's replica. Zero replicates schema
changes through PostgreSQL event triggers; providers without event triggers need
[Zero's schema-change hooks](https://zero.rocicorp.dev/docs/connecting-to-postgres)
or its automatic reset behavior. A full upstream database reset is different:
stop the cache, clear its SQLite replica files, and restart it, as described in
[Zero's development guidance](https://zero.rocicorp.dev/llms.txt) and
[replication troubleshooting](https://zero.rocicorp.dev/docs/debug/replication).
Do not delete replica files during every migration or while the cache is running.

Generation writes `resources/js/zero/generated.ts`. Use `--output=/path/file.ts`
to override it, and `--check` in app CI to detect drift. Exclude the generated
file from formatters so `--check` can compare its exact contents.

The same column plan drives TypeScript and the dedicated PostgreSQL publication.
Eloquent's nonempty `$visible` list is the default database-column allowlist;
an empty list allows all database columns. `#[ZeroColumns([...])]` replaces that
default with an explicit replication allowlist. `$hidden` and
`#[ZeroExclude(['secret'])]` add exclusions to either allowlist.
`#[ZeroIncludeColumns([...])]` explicitly opts a non-visible, hidden, or excluded
column back in, but cannot add columns outside an explicit `ZeroColumns` list.
Names in `$visible` that are not database columns (such as accessors or relations)
are ignored. Required keys, ownership fields, timestamps, or relationship keys
omitted by these controls cause generation to fail; they are never added silently.
Pivot tables publish only correlation and primary-key columns. Use a dedicated
publication; synchronization replaces its table/column membership atomically.

Start the cache with the explicit publication. Omitting it lets native Zero
create a broad default publication. In the cache's environment:

```dotenv
ZERO_APP_ID=zero
ZERO_APP_PUBLICATIONS=laravel_zero
ZERO_UPSTREAM_DB=postgres://user:password@localhost:5432/app
ZERO_CVR_DB=postgres://user:password@localhost:5432/zero_cvr
ZERO_CHANGE_DB=postgres://user:password@localhost:5432/zero_changes
ZERO_QUERY_URL=http://localhost:8000/zero/query
ZERO_MUTATE_URL=http://localhost:8000/zero/mutate
ZERO_QUERY_API_KEY=your-generated-private-secret
ZERO_MUTATE_API_KEY=your-generated-private-secret
```

Create the named databases, configure PostgreSQL logical replication and the
required replication privileges, then run the pinned `zero-cache` binary. See
[Zero cache configuration](https://zero.rocicorp.dev/docs/zero-cache-config) for
deployment, replication slots, persistent cache storage, and origins.
This package does not provision or deploy the cache.

In Laravel's environment, use the same app identity and secret:

```dotenv
ZERO_APP_ID=zero
ZERO_UPSTREAM_SCHEMA=zero_0
ZERO_PUBLICATION=laravel_zero
ZERO_API_KEY=your-generated-private-secret
```

`ZERO_UPSTREAM_SCHEMA` is a Laravel setting and must match the cache's shard schema
(`<app-id>_0`). Zero creates its own `clients` and `mutations` bookkeeping tables;
do not migrate competing tables. Keep the cache API key out of browser code.
It authenticates the cache, while the configured Laravel middleware separately
authenticates the end user.

For Laravel session authentication, set `zero.middleware` to `['web', 'auth']`,
exclude only `zero/query` and `zero/mutate` from Laravel CSRF validation in
`bootstrap/app.php`, and set both `ZERO_QUERY_FORWARD_COOKIES=true` and
`ZERO_MUTATE_FORWARD_COOKIES=true` in the cache. Serve the app and cache through a
cookie-compatible origin/proxy. The endpoints still require the private cache
API key and the authenticated session. For token authentication, configure your
API guard and use Zero's `auth` token or allowed client authorization headers.
The default middleware is `['api', 'auth']`; choose the guard/session setup for
your application. Keep Zero's periodic auth revalidation and query retransformation
enabled so server context changes are reevaluated.

## React

Generate the optional React wrapper:

```sh
php artisan zero:generate --react
```

This writes `generated.react.ts` beside the contracts and creates an editable
`zero.config.ts` once. Set its public `cacheURL` and optional query/mutation URLs;
regeneration never overwrites that configuration. Set `zero.react` to `true`
to include the wrapper in ordinary generation and `--check`.

```tsx
import { ZeroProvider } from './zero/generated.react';

<ZeroProvider userID={String(user.id)} context={{ user_id: user.id }}>
    <App />
</ZeroProvider>;
```

The wrapper binds schema/mutators, stabilizes equivalent scalar context objects,
and replaces the native provider when user/context identity changes. Context
also partitions local storage, including when switching tenants. An optional
`auth` prop supports token renewal. The native provider owns construction and cleanup.
The generic native `ZeroProvider` remains available from the React package for
applications that manage Zero themselves.

```tsx
import { useData, useFilter, useMutation } from '@nickwelsh/zero-for-laravel/react';
import { Task } from './zero/generated';

function Tasks() {
    const [title, TitleFilter] = useFilter(Task, 'title', { debounceMs: 150 });
    const tasks = useData(Task.query().all().with('list').filter(title).infinite(50));
    const create = useMutation(Task.create());

    return (
        <>
            <TitleFilter label="Search tasks" />
            <button disabled={create.state.status === 'pending'} onClick={() => create({ title: 'Hello' })}>
                Add task
            </button>
            {tasks.data.map((task) => (
                <p key={task.data.id}>{task.data.title}</p>
            ))}
            <button disabled={tasks.infinite?.hasMore !== true} onClick={() => tasks.infinite?.loadMore()}>
                Load more
            </button>
        </>
    );
}
```

`useData` exposes `data`, `complete`, native `details`, `connection`, `error`,
`retry()`, and a `status` of `idle`, `loading`, `refreshing`, `failure`, `stale`, or
`success`. Disabled queries are idle; incomplete empty results are loading;
incomplete current-query rows are refreshing; complete empty results succeed.
Previously completed empty results remain usable during background synchronization and become stale on error. Query errors with usable data are stale; errors without usable data are failures.
Connection state remains separate. `render()` accepts the six matching handlers:
`onIdle`, `onLoading`, `onRefreshing(rows)`, `onFailure(error)`,
`onStale([rows, error])`, and `onSuccess(rows)`.

Other query examples:

```ts
Task.query().one(id).with('list'); // Row | undefined, including visibility-filtered relations.
Task.query().all().orderBy('title').limit(25);
Task.query().all().withTrashed();
Task.query().all().onlyTrashed();
// If a declared direct to-many relation is named comments:
Task.query().all().with('comments', 5);
// If list declares a tasks include:
Task.query()
    .all()
    .with('list', (list) => list.with('tasks', 5));
```

Builders are immutable and track included result shapes. Singular queries allow
includes but not filters or pagination. Infinite loading expands one live ordered
query, uses a lookahead row, resets on query/client changes, and respects `maxLimit`.
`hasMore` is unknown until synchronization completes and false when the window cannot grow. `capped` reports the maximum; `exhausted` proves no further rows exist, and `stopReason` is `maximum`, `exhausted`, or undefined. An exact-cap result is capped without proving exhaustion. This grows a bounded live result window; it does not guarantee unrestricted dataset traversal.

`useFilters(Task, ['done', 'title'])` returns `[filters, Controls]` for
`.filters(filters)`. `useFilter` also returns a third setter. Undefined unsets a
filter; null, false, and empty strings remain distinct. Substring filters accept
strings only. Custom per-filter controls use `FilterControlProps<Value>`;
`FilterProvider` can supply shared control defaults. Controls retain their identity
while editing. Initial values/options belong to the mounted filter binding.
The basic date control edits UTC; use a custom control for local-calendar semantics.

Mutations return native `client` and `server` completion promises plus the stable
record `id`. `state` reports pending/success/failure and field errors. `form` is
also a Formbraid descriptor: pass it to your registered `<Form form={form} />`.
It includes writable field definitions, defaults, and a callback that waits for
server acceptance and maps field errors. Selecting `.with('person')` adds fields
such as `person.first_name`. Formbraid owns editing and submission, and date
fields edit strings that the callback converts to native Dates.

The existing manual controls (`form.values`, `form.setValue()`, `form.errors`,
`submit()`, and `reset()`) remain available. Bound update forms submit changed
fields plus fields explicitly required by update rules. Handle both synchronous
`InputError` from direct preparation and asynchronous mutation outcomes.

```ts
const update = useMutation(task.update());
const call = update({ completed_at: new Date() });
const result = await call.server;
// result.type is 'success' or 'error'; inspect result.error on failure.
```

Outside React, use `zero.mutate(Task.create().prepare(input))`, or
`Task.query().all().decode(await zero.run(Task.query().all().request()))`.
Public dates are `Date`; native Zero rows/requests use epoch milliseconds.
`HasUuids` uses UUIDv7 and `HasUlids` uses ULID. IDs and the optimistic clock are
captured once before enqueueing. Eloquent timestamp column constants are respected.
For millisecond-preserving writes, use temporal columns with matching precision
and an Eloquent `$dateFormat` such as `Y-m-d H:i:s.u`.

To customize the client clock or generated-ID provider, call
`createZeroForLaravel(schema, definitions, {now: () => new Date(), id: (kind, model) => ...})`
and use its returned models/queries/mutators together. Custom IDs must still match
the declared UUID/ULID strategy. Native Zero options configure storage and transport.

Records are model snapshots: columns and loaded relations live under `task.data`.
Included relations contain snapshots too (`task.data.list?.data.name`). Instance
methods such as `task.update()` and `task.destroy()` bind the record ID and remain
callable when destructured. For lazy relations, `useData(task.list)` projects the
relation through an authorized parent `one` query; targets need no top-level query.
Lazy access requires the parent to opt into `one` and the relation to be an allowed include.
Generated exports include both `Task` (instance type and model value) and `TaskRow`
(the column-only type). Both `Task.query.all()` and `Task.query().all()` work.

See [model API details](docs/model-api.md) for server scopes, richer filters,
cast/DTO typing, form usage, and field-level write authorization.

## Supported constraints

- Automatic `all`/`one` and create/update/destroy/restore/force_delete only.
  Custom queries/mutations, upsert, and Inertia adapters are deferred.
- One upstream connection, unprefixed tables in `public`, one model per table,
  and unique model short names. Creates require standard `HasUuids` or `HasUlids`;
  existing numeric-key models can read/update/delete without optimistic creates.
- Plain belongs-to, has-one, has-many, and belongs-to-many relations. Pivots need
  real primary keys and cannot also be registered as models. Constrained or
  polymorphic Eloquent relations fail generation.
- Direct to-many includes can be limited. Native Zero junction includes cannot
  be ordered or limited. Includes nest to a maximum depth of eight.
- Portable SQL scalar/JSON columns and date/timestamp columns. JSON filtering and
  sorting, SQL time-only fields, custom database types, and arrays are unsupported.
  JavaScript numeric precision applies to bigint/decimal data.
- Eloquent scopes, accessors, encrypted casts, appended properties, and arbitrary
  PHP logic do not execute on replicated reads. Keep sensitive stored columns
  excluded and express visibility using the Zero definition. Supported primitive casts are
  hydrated, backed enums use literal unions, and DTO JSON shapes can use optional
  TypeScript module augmentation without requiring Laravel Data.
- Server-only validation, normalization, defaults, timestamps, and model events
  can reconcile optimistic values. Keep external event side effects after commit.

## Development and verification

The [v0.x documentation site](apps/docs/README.md) uses
[`@nickwelsh/docs-base`](https://github.com/nickwelsh/docs-base), with guides and
Refquill-generated PHP and TypeScript API references. Run `pnpm docs:dev` from
the repository root and open `http://localhost:3000/zero-for-laravel/`. Use
`pnpm docs:build` and `pnpm docs:start` for a production preview, or
`pnpm docs:generate` to regenerate the API reference independently.

```sh
composer --working-dir=packages/laravel install
pnpm install --frozen-lockfile
pnpm check
# Optional coverage reports:
pnpm test:coverage
XDEBUG_MODE=coverage pnpm php:test:coverage
```

The PostgreSQL tests are skipped unless `ZERO_TEST_PG_DATABASE` is set. Create an
empty disposable database named `laravel_zero_test` (or `laravel_zero_test_*`),
set optional `ZERO_TEST_PG_HOST`, `PORT`, `USER`, and `PASSWORD`, then run
`ZERO_TEST_PG_DATABASE=laravel_zero_test composer --working-dir=packages/laravel test`. The harness refuses an
existing application schema and removes only its owned fixtures.

Tests cover metadata/privacy, PHP/native Zero AST equivalence, FormRequests,
policies, real native optimistic CRUD, protocol ordering/rollback/rejections,
PostgreSQL publications, React states, pagination, and filter controls. CI checks
Laravel 12/13 and PHP 8.3/8.5. A live cache deployment is still a separate end-to-end
verification step. See [architecture](docs/architecture.md) for the core invariants.

MIT licensed.

See [tooling](docs/tooling.md) for workspace commands, supported runtimes, and the
settings adapted or omitted from the Laravel application reference.

## v0.3.0 correctness and development additions

The source adds typed filter shorthand, `toPlain()` projection, live sort retention,
explicit infinite-window stop reasons, optional FormBraid, and named domain actions.
Package-owned `zero:dev`, `zero:doctor`, `zero:exposure`, and guarded replica-file
reset commands replace reusable local startup plumbing. See [the changelog](CHANGELOG.md),
[local startup and recovery](apps/docs/content/docs/0.x/docs/local-development.mdx),
[domain mutations](apps/docs/content/docs/0.x/docs/domain-mutations.mdx), and
[publication-sensitive migrations](apps/docs/content/docs/0.x/docs/replication.mdx).
