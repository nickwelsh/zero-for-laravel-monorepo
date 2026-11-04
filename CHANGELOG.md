# Changelog

## [0.6.0] - 2026-11-04

- Add Formbraid **0.2** integration for native Zero controller mutations.
  `zero:formbraid` generates standard descriptors and bound factories from
  FormRequests: import a controller's `store` and render `<Form form={store} />`.
  Submissions retain Laravel validation, authoritative results, cancellation
  semantics, and the application's Zero session and mutation preparation.
- Add managed Casbin policies shared by Laravel Gates, Eloquent `visibleTo()`
  collections, and native Zero predicates. Transactional permission projections
  maintain grants, role assignments, and subject/resource facts without exposing
  private permission tables in browser schemas or query results.
- Add opt-in `VisibleToCurrentUser` global scoping, native subscribed access-loss
  handling, and advisory current-user permission predictions. Access invalidation
  affects only the relevant permission decisions; browser predictions never
  authorize reads or writes.
- Add application-owned typed Zero context, portable Eloquent-style scopes, and
  `withAttributes()` creation defaults. Explicit creation attributes take precedence;
  defaults do not stamp updates or replace authorization.
- Remove pre-controller-first compatibility APIs and legacy model definitions.
  Harden malformed-quote search parsing with a single-pass scanner, refresh API
  documentation, and update the authenticated shared-todos example.

Use matching PHP and JavaScript **v0.6.0** packages with Zero client/cache **1.11.3**.
Configure the application-owned context enum and resolver, migrate legacy model
definitions to the controller-first APIs, and regenerate/deploy PHP and TypeScript
contracts together. Policy visibility is opt-in: publish its configuration and
migrations, rebuild projections, and synchronize the publication before serving
protected live queries. Plan production migrations and incompatible queued work
separately; do not reset application data as an upgrade step. Formbraid support is
optional and requires its **0.2** packages.

## [0.5.0] - 2026-10-31

Initial release.

- Controller-first live queries and explicitly attributed mutations. Generation
  emits coordinated PHP registrations, native Zero requests/mutators, validators,
  result codecs, and named controller modules.
- Zero **1.11.3** native optimistic transactions, replay, progress, and persisted
  results. Committed outcomes recover after lost responses without repeating writes;
  generated, custom, and disabled optimism use the same protocol.
- Automatic Inertia live props with plain decoded rows, precise dates/cursors,
  partial/history handling, SSR loading shells, and identity-scoped subscriptions.
- Typed editable FormRequest inputs, finite enum/`in` choices, pagination reset,
  and opt-in scalar URL/history synchronization without server visits.
- Prepared UUID/ULID/time overrides, checked shared helpers, bounded keyed loops,
  lock/reload primitives, and unique-key conflict recovery for conditional insertion
  and explicit upserts. External work stays server-only and outbox-owned.
- Model visibility, context fills, column privacy, restricted PostgreSQL publications,
  and optional scoped Scout/Typesense search with authorized row hydration.
- Native query/mutation inference and optional Wayfinder `dev-next` page typing
  without duplicate global declarations.
- Local development commands, generated API documentation, a supervised todos demo,
  and real Laravel/PostgreSQL/Zero integration coverage.

Use matching PHP and JavaScript **v0.5.0** packages with Zero client/cache **1.11.3**.
Deploy generated PHP and TypeScript contracts together. Supported targets are PHP
8.3+, Laravel 12/13, PostgreSQL 15+, and React 18/19. Automatic live pages require
Inertia Laravel 3 and `@inertiajs/react` ^3.8.0.

[0.6.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/releases/tag/v0.6.0
[0.5.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/releases/tag/v0.5.0
