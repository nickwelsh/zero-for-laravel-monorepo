# Controller-first architecture

The Composer and npm packages target Zero **1.11.3**. Controllers are the query and
mutation declarations; model metadata owns storage, casts, visibility, relationship
paths, writable fields, and publication privacy. The design baselines are
[queries](QUERY_DESIGN.md) and [mutations](MUTATION_DESIGN.md).

## Generation has one semantic source

Ranger discovers registered routes. Surveyor supplies request analysis. The checked
PHP AST compilers lower supported controller expressions into owned query/operation
definitions; PHP and TypeScript emission consume those definitions. Unsupported
dependencies fail generation. No controller executes to discover sample values.

Generated identities include relevant source and model contracts. Deploy PHP
registrations and client modules together. Unknown old names reject with a reload
or unsupported-operation error rather than executing new code under stale identity.
An application that needs offline-operation retention must retain old registrations
deliberately; v0.5 does not transparently migrate queued intent from v0.4.

## HTTP and Zero retain distinct jobs

An ordinary page request runs Laravel's real controller. The Inertia adapter removes
live props before serialization and emits serializable `__zero` metadata. The client
wrapper owns subscriptions and decoded rows outside Inertia history. Partial metadata
patches preserve omitted bindings and explicitly remove replaced bindings.

A query transform validates arguments, reruns required route middleware/binding and
FormRequests, resolves fresh trusted context, and executes its generated query
registration. It does not rerun the page controller. Root, related, and existence
queries receive visibility predicates. A browser cannot supply an authoritative AST.

Mutations use the same native protocol for generated, custom, and disabled optimism.
Laravel executes the registered action boundary within the upstream transaction.
Prepared IDs/time stay outside business input. Writes, LMID, and durable JSON outcome
commit consistently. Rejection rolls back writes before recording progress/error.
Duplicates do not repeat writes. The native cleanup mutation removes consumed results.

## Shared execution is bounded

Generated operations use Eloquent on the server and native Zero transactions locally.
Explicit shared helpers preserve statement order and read-your-writes. Unique-key
insertion uses a savepoint and reloads a concurrent winner without overwriting an
existing preference. State-dependent policies can follow `reloadForUpdate()`.

Bounded keyed loops derive UUIDs from prepared per-site seeds and explicit item keys,
never traversal positions. Missing bound cache rows produce a declared no-op before
writes. Late failures are not swallowed to commit partial speculative effects.
Server-only regions never run during browser replay. Durable external work belongs
in application-owned transactional outbox rows and an idempotent worker.

Public validation and date encoding occur before native request construction.
Canonical JSON wire arguments define request identity. Decoding applies recursively
after native reads; raw cursor values preserve sub-millisecond storage precision.

## Verification follows the boundaries

Tests use pinned native ZQL as the AST oracle, native mutators for optimistic behavior,
Laravel HTTP tests for validation/binding/security, and PostgreSQL for atomicity and
unique-key conflict recovery. A real zero-cache integration test discards a committed
mutation response and verifies native delivery of the persisted result without a
second creation. Browser checks exercise the actual shared todos application.
