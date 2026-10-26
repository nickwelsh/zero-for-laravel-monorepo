# Changelog

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

[0.3.0]: https://github.com/nickwelsh/zero-for-laravel-monorepo/compare/v0.2.1...v0.3.0
