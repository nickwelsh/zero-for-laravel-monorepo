# Changelog

## [0.7.0] - Unreleased

- Add `@nickwelsh/zero-table` **0.1.0**, a headless editable table with React hooks,
  unstyled components, independent nested grouping, virtualized window/panel
  scrolling, and infinite live row prefixes. Laravel table definitions declare
  allowed filters, sorts and groups once and generate native Zero queries plus
  authorized SQL snapshot counts and directories.
- Add opt-in background preloading with a shared 1,000-requested-row / 8-query
  budget and batches of 100, configurable collapsed-group hover loading, and
  40-item viewport lookahead. Budgets constrain background demand, not explicit
  expansion, scrolling, or total native cache memory. Cold bulk hydration can
  still stall; no experimental Zero dependency patch is included.
- Add gated inline cell editing, scalar/custom editors, and an optional Formbraid
  bridge with searchable, independently resolved, infinite relation choices.
  Move the optional Formbraid integration to **0.3** across PHP and JavaScript.

Upgrade the PHP and client packages together to **0.7.0**, then regenerate and
deploy PHP/TypeScript contracts together. The table package requires client
**^0.7.0** and Zero client/cache **1.11.3**. Formbraid users must upgrade
`formbraid/laravel`, `@formbraid/core`, and `@formbraid/react` together to **^0.3.0**
and regenerate form descriptors. Counts remain snapshots, and background preload
defaults to off. Existing applications do not need Formbraid or the table package.

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

[0.7.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/compare/v0.6.0...HEAD
[0.6.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/releases/tag/v0.6.0
[0.5.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/releases/tag/v0.5.0
