# Zero for Laravel

Controller-first live queries and mutations for Rocicorp Zero **1.11.3**.

This is an unreleased pre-alpha rewrite. Use the matching Composer path and pnpm
workspace revisions; registry releases do not contain this code. PHP 8.3+, Laravel
12/13, and PostgreSQL with logical replication are required targets.

Controllers declare immutable `Model::zeroQuery()` live props and explicitly opt
actions into `#[ZeroMutation]`. Ranger/Surveyor-backed generation emits coordinated
PHP registrations, native ZQL/mutators, validation schemas, result codecs, and page
types. Laravel retains real binding, middleware, FormRequests, Gates, and Eloquent.
Zero retains replication, optimistic replay, ordering, and durable mutation results.

```sh
php artisan vendor:publish --tag=zero-config
php artisan zero:generate --react
php artisan zero:sync-publication --dry-run
```

Review and apply publication changes to the application's configured PostgreSQL
database. Deploy generated PHP and TypeScript together; use `zero:generate --check`
in CI. Keep the cache API key server-side and independently authenticate end users.

See the [monorepo guide](https://github.com/nickwelsh/zero-for-laravel-monorepo#readme)
and [shared todos example](https://github.com/nickwelsh/zero-for-laravel-monorepo/tree/main/examples/todos).
Development and issues belong in the source monorepo, not the distribution mirror.
