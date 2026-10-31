# Changelog

## [0.5.0] - 2026-10-31

- Replace the model-centric application API with controller-first live queries and
  explicitly attributed mutations. Generate coordinated PHP registrations, native
  Zero requests/mutators, validators, result codecs, and named controller modules.
- Move to Zero **1.11.3** and its native optimistic transactions, replay, progress,
  and persisted results. Recover committed outcomes after lost responses without
  repeating writes; keep generated/custom/disabled optimism on the same protocol.
- Add automatic Inertia live props with plain decoded rows, precise dates/cursors,
  partial/history handling, SSR loading shells, and identity-scoped subscriptions.
- Add typed editable FormRequest inputs, finite enum/`in` choices, pagination reset,
  and opt-in scalar URL/history synchronization without server visits.
- Add prepared UUID/ULID/time overrides, checked shared helpers, bounded keyed
  loops, lock/reload primitives, and unique-key conflict recovery for conditional
  insertion and explicit upserts. Keep external work server-only and outbox-owned.
- Expose native query/mutation inference and optional Wayfinder `dev-next` page
  typing without duplicate global declarations. Upgrade the workspace to TypeScript 7.
- Rewrite development and migration guides, regenerate PHP/TypeScript/Inertia API
  reference docs, and persist the supervised single-origin todos orb demo.
- Fix example request rule typing and preserve local-only proxy trust with cached
  Laravel configuration; document an isolated encryption key for example tests.

### v0.5.0 adoption (breaking)

Use matching PHP and JavaScript **v0.5.0** with Zero client/cache **1.11.3**.
Migrate model snapshot/CRUD and named-domain callers to controller queries/actions;
regenerate and deploy the entire PHP/TypeScript contract set together. Inertia
live pages require Inertia Laravel 3 and `@inertiajs/react` ^3.8.0. PHP 8.3+,
Laravel 12/13, PostgreSQL 15+, and React 18/19 remain supported targets.
Wayfinder integration is optional and targets its `dev-next` converter API.

This is not a drop-in v0.4 upgrade. Queued operations from incompatible contracts
are not transparently migrated. Plan their settlement/retention before rollout;
do not resubmit writes after a committed-result decode failure. No package-owned
application data migration is required, and cache/database deletion is not a
routine upgrade step. See the [upgrade guide](apps/docs/content/docs/0.x/docs/upgrading.mdx).

## [0.4.0] - 2026-10-28

- Add optional Scout/Typesense search metadata, explicitly scoped authenticated
  search transport, InstantSearch React bindings, and metadata-driven search syntax
  and completion. Hydrate search identifiers through authorized Zero queries;
  search counts describe the index, not the hydrated records.
- Add bounded scalar-set and relationship filters, nested all/any condition groups,
  and escaped portable patterns with matching PHP and client semantics.
- Share execution and FormBraid form creation across model CRUD and named actions,
  including bound inputs, field selection, defaults, and authoritative validation.
- Add optional typed domain-operation results through a subsequent authenticated
  read. Failed result reads distinguish committed writes and can be retried without
  repeating the mutation.
- Prepare cached Amp orb toolchains, dependencies, and disposable PostgreSQL;
  exclude PHPStan 2.3.4 to keep fresh CI dependency resolution compatible with Rector.

### v0.4.0 adoption

Use matching PHP and JavaScript **v0.4.0** packages with Zero **1.10.0**, then
regenerate contracts. PHP 8.3+, Laravel 12/13, and React 18/19 support are unchanged.
Search is opt-in: install/configure Scout and Typesense and the optional browser
integration peers only when using search. Explicit trusted search scopes remain
required; index hits never replace Zero authorization. Operation result reads must
reapply owner/tenant scope and are not snapshots of the original write transaction.
No package-owned database migration is required. See [model API details](docs/model-api.md).

## [0.3.0] - 2026-10-26

- Accept transform batches beyond 100 queries (257 valid queries covered), while
  retaining private callback authentication, the 1 MiB HTTP body limit, invocation
  validation, and per-query authorization. Oversized bodies use HTTP 413; malformed
  envelopes retain protocol parse errors.
- Keep completed empty results usable and retain a live committed subscription
  during root sort transitions. Scope/contract/identity changes isolate data.
  Equivalent inline selections reuse decoding within their hook and client.
- Distinguish exhausted infinite results from a maximum-limited live window with
  `exhausted` and `stopReason`; `hasMore` never offers impossible cap advancement.
- Add typed selection-local `.filter(name, value)` and recursive readonly
  `toPlain()` projection. Existing filter objects and snapshot methods remain.
- Make FormBraid optional, including declaration isolation for query-only consumers.
- Add local `zero:dev`, read-only `zero:doctor`, `zero:exposure`, and guarded,
  explicit replica-file-only `zero:reset`. No PostgreSQL cleanup is performed.
- Add publication-sensitive `PublicationManager::releaseTable()` and resolve final
  metadata only after successful migration batches on the upstream connection.
- Add explicitly registered bounded domain mutations with generated input types,
  native Zero bindings, optional deterministic optimistic callbacks, FormRequest
  authorization/validation, and the existing mutation protocol/transaction engine.
- Add configurable opt-in model discovery roots and real PostgreSQL/Zero integration
  coverage with unique disposable database, publication, app, and replica identities.

### Adoption

Use PHP and JavaScript **v0.3.0** together with Zero **1.10.0**. Publish/merge
new configuration keys as needed and regenerate contracts after model or domain
registration changes. Existing applications without domain actions retain the
same generated model exports. No application database migration is required by
these package changes. Read the local development and domain mutation guides
before opting into the new commands or action registrations.

Full database decommission is intentionally outside `zero:reset`; external effects
remain application-owned and should use after-commit jobs or an outbox. Cache root
health and publication synchronization do not establish completed backfill.

[0.5.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/compare/v0.4.0...v0.5.0
[0.4.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/compare/v0.3.0...v0.4.0
[0.3.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/compare/v0.2.1...v0.3.0
