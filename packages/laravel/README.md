# Zero for Laravel

Controller-first live queries and mutations for Rocicorp Zero **1.11.3**.

Use **v0.5.0** with the matching npm package. Generate and deploy PHP registrations
and TypeScript contracts together. PHP 8.3+, Laravel 12/13, and PostgreSQL 15+ with logical replication are
required targets. Automatic live page props require Inertia Laravel 3 and the
optional JavaScript Inertia adapter.

Controllers declare immutable `Model::zeroQuery()` live props and explicitly opt
actions into `#[ZeroMutation]`. Ranger/Surveyor-backed generation emits coordinated
PHP registrations, native ZQL/mutators, validation schemas, result codecs, and
named controller modules. Laravel retains real binding, middleware, FormRequests, Gates, and Eloquent.
Zero retains replication, optimistic replay, ordering, and durable mutation results.

Zero does not generate global page types. Optionally register
`ZeroForLaravel\WayfinderServiceProvider` with Wayfinder `dev-next` to specialize
its own generated live props; run Zero generation before Wayfinder generation.

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
