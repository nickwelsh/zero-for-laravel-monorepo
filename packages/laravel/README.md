# Zero for Laravel

Laravel-owned model contracts, queries and automatic CRUD for Rocicorp Zero.

Install the current stable release, **v0.3.0**, in your Laravel application:

```sh
composer require nickwelsh/zero-for-laravel
pnpm add @nickwelsh/zero-for-laravel
pnpm add --save-exact @rocicorp/zero@1.10.0
php artisan vendor:publish --tag=zero-config
```

Keep the Composer and JavaScript packages on matching release versions. Use Zero
cache **1.10.0**; React bindings additionally require React 18 or 19.

Development takes place in the [source monorepo](https://github.com/nickwelsh/zero-for-laravel-monorepo).
`nickwelsh/zero-for-laravel` is the generated PHP distribution mirror. Send issues and
pull requests to the monorepo; changes made directly to the mirror are not supported.

See the [monorepo documentation](https://github.com/nickwelsh/zero-for-laravel-monorepo#readme) for installation, authorization, configuration and React usage.

Requires PHP 8.3+ and Laravel 12 or 13.

## v0.3.0 additions

`zero:dev` owns local contract generation, publication synchronization, and cache
startup. `zero:doctor` separates prerequisites and process health from unknown
callback/backfill readiness. `zero:exposure` reports model permissions and columns.
`zero:reset --dry-run` inventories an owned local replica; `--force` removes only
its exact local files after process/slot checks, preserving PostgreSQL data.

Domain mutations are explicitly registered `DomainMutation` classes backed by
Laravel FormRequests and the existing mutation processor. Publication-sensitive
migrations can call `PublicationManager::releaseTable()` before destructive DDL;
final metadata is restored after the successful batch. See the source documentation
for configuration, ownership, failure recovery, and deployment ordering.
