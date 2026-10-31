# Shared todos

A Laravel 13 React starter application with session authentication and native Zero
controller-declared live queries and controller mutations. It consumes the local Composer package in
`../../packages/laravel` and the `@nickwelsh/zero-for-laravel` pnpm workspace package.

- Application: <https://todos.test>
- Zero cache: <https://zero.todos.test> (local port 4858)
- Owners create, rename, delete, and share lists with registered users.
- Every member can add, edit, complete, and delete todos.
- Private lists and todos are hidden from other users. User/auth tables are never
  published to Zero. Sharing uses a registered controller mutation with explicitly
  disabled optimism, preserving Laravel validation, binding, and policies.

## Requirements

Use Herd, PHP 8.4.1+, Node 24+, pnpm 11, and PostgreSQL 15+ with logical replication
(`wal_level=logical`) and replication privileges for the local database user.
The example uses Pest 5; the library itself supports PHP 8.3 with Laravel 12.

## Install

From the repository root:

```sh
composer --working-dir=packages/laravel install
composer --working-dir=examples/todos install
pnpm install --frozen-lockfile
pnpm build
cd examples/todos
cp .env.example .env
php artisan key:generate
```

Create an empty PostgreSQL database named `laravel-zero-todos`. The included
environment template uses:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=laravel-zero-todos
DB_USERNAME=root
DB_PASSWORD=
```

Generate a private cache key, then put its output in `ZERO_API_KEY` in `.env`:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Keep this key server-side. The template sets `APP_URL=https://todos.test`,
`SESSION_DOMAIN=.todos.test`, secure session cookies,
`VITE_ZERO_CACHE_URL=https://zero.todos.test`, and `ZERO_APP_ID=todos`.

From `examples/todos`:

```sh
herd link todos
herd secure todos
herd proxy zero.todos http://127.0.0.1:4858 --secure
php artisan migrate
php artisan zero:generate
pnpm build
composer dev
```

Leave `composer dev` running. It invokes `php artisan dev` and starts Vite, Zero,
the queue listener, and Pail together. Herd serves Laravel over HTTPS, so the default
Artisan HTTP server is excluded. Stop the command with Ctrl+C to stop its processes.
No separate frontend or cache terminal is needed. After changing model contracts,
regenerate; the migration hook synchronizes the publication automatically.
`pnpm zero:dev` remains available for running the cache on its own.

The cache script trusts Herd's local CA with `NODE_EXTRA_CA_CERTS` when it exists.
Override `ZERO_CA_CERT` or `ZERO_HERD_CA` if your CA lives elsewhere. HTTP development
does not require Herd or a certificate. It never disables TLS verification.
The upstream, CVR, and change databases use the same local PostgreSQL database,
with separate internal Zero schemas keyed by `todos`; `ZERO_UPSTREAM_SCHEMA=todos_0`
matches the cache. The replica is stored in ignored `storage/app/zero`.
The dedicated `laravel_zero_todos` publication contains only the domain tables.

## Generated page types

This example uses Wayfinder `dev-next` with the published `config/wayfinder.php`.
Inertia component metadata and enum-method generation are enabled. Routes and
controller actions are imported from `@/wayfinder/routes` and `@/wayfinder/App/...`.
The Vite plugin generates Zero contracts before Wayfinder; `pnpm zero:generate`
does the same with a fresh Wayfinder analysis.

Dashboard, profile, and security use `Inertia.Pages` from `@/wayfinder/types`.
Shared props and user models also come from Wayfinder, without handwritten global
page overrides. Authenticated pages assert the user guaranteed by their route
middleware; shared data still correctly permits guests. Security props have an
explicit PHP return shape, with two-factor flags always boolean (false when disabled).
Fortify's view callbacks are not discovered by the current Wayfinder analyzer, so
their auth-page-specific props remain locally typed.

## Migrations and database resets

`zero.sync_publication_on_migrate` is enabled for this application. Successful
PostgreSQL migration batches synchronize the dedicated publication, including
`migrate:fresh` and `migrate:refresh`. Ordinary migrations retain the SQLite replica.
The hook excludes pretend runs, rollbacks, and SQLite test migrations. For manual
repairs without pending migrations, run `php artisan zero:sync-publication`.

A full database reset needs a fresh Zero replica too. First stop `composer dev`
with Ctrl+C, then run from `examples/todos`:

```sh
php artisan migrate:fresh --seed
php artisan zero:generate
rm -f storage/app/zero/replica.db*
composer dev
```

This resets the local application data and discards only Zero's derived replica
files (including SQLite sidecars). Zero rebuilds them from PostgreSQL and resyncs
connected clients. The publication is restored automatically after migrations.
Use the same replica cleanup if local replication becomes wedged. Never remove
these files while the cache is running; do not clear them on ordinary migrations.

See [Zero's database reset guidance](https://zero.rocicorp.dev/llms.txt),
[replication troubleshooting](https://zero.rocicorp.dev/docs/debug/replication), and
[auto-reset configuration](https://zero.rocicorp.dev/docs/zero-cache-config#auto-reset).
`ZERO_AUTO_RESET` defaults to true, but reacts to replication-halting errors; it
is not a replacement for the explicit full-database reset workflow above.

## Demo accounts

Optional, in the local environment:

```sh
php artisan db:seed --class=TodosDemoSeeder
```

Sign in as `alice@example.com` or `bob@example.com` with password
`todos-demo-password`. Alice owns Household (shared with Bob) and Personal
(private). These are fictional local accounts. The idempotent seeder does not
reset existing passwords and refuses non-local environments.
You can also register accounts through the starter kit.

## Authorization

A list has one owner and explicit memberships, including its owner. List queries
use `ZeroAuthorizeRelation('memberships')`; memberships are visible only to their
user. Todo queries use `ZeroAuthorizeRelation('list')`. Policies enforce writes,
and FormRequests validate destination-list access, preventing a member from moving
a todo into someone else's private list. Deleting a list cascades its todos and
memberships. Sharing adds membership by registered email; duplicate shares are safe.

`DashboardController` declares the list query, its related todos, ordering, and live
window. The Inertia adapter injects plain typed live rows. `TodoController`,
`TodoListController`, and `ShareListController` explicitly opt into Zero mutations;
the frontend calls generated action bindings, not model-level automatic CRUD.

List creation inserts its owner membership on the server. The new list becomes
visible once that membership replicates; the example waits for native server
completion before selecting it. Direct membership mutations are not exposed.
Revocation/invitations and richer sharing management are outside this example.

## Checks

From `examples/todos`:

```sh
php artisan test --compact
php vendor/bin/phpstan analyse --no-progress
php vendor/bin/pint --test
pnpm check
pnpm types:check
pnpm build
php artisan zero:generate --check
```

Tests use in-memory SQLite, never the local application database. PHP tests cover
shared CRUD, owner-only list deletion/sharing, outsiders, validation, destination
access, duplicate shares, cascade deletion, and generated query authorization.
Frontend lint/format rules belong to the root Vite+ config. Generated Zero and
Wayfinder code and build outputs are excluded from formatters/lint, with generated
contracts checked separately. No new lint rules are disabled for this example.
