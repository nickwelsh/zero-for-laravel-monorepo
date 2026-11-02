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

The application supplies a string-backed context enum and attributed resolver
methods. Generation reflects one native scalar/nullable return signature per
case without invoking methods; runtime Laravel DI resolves trusted values into
`ResolvedContext`. Native Zero identity remains independent of application context
keys. Both `userID` and typed `context` are supplied to the client provider.

`ZeroQuery` is a separate mutable declaration builder. Local `#[ZeroScope]` methods
and registered Zero global scopes share the checked query subset, not arbitrary
Eloquent behavior. Clone explicitly for independent branches. Eloquent relationship
methods between opted-in models provide checked schema paths; `with` includes rows
and `whereHas` filters parents, lowering to native related/EXISTS semantics. There
is no silent enrollment or column expansion, and Eloquent relations stay Eloquent.

Generated identities include relevant source and model contracts. Deploy PHP
registrations and client modules together. Unknown names reject with a reload
or unsupported-operation error rather than executing new code under stale identity.

## HTTP and Zero retain distinct jobs

An ordinary page request runs Laravel's real controller. The Inertia adapter removes
live props before serialization and emits serializable `__zero` metadata. The client
wrapper owns subscriptions and decoded rows outside Inertia history. Partial metadata
patches preserve omitted bindings and explicitly remove replaced bindings.

A query transform validates arguments, reruns required route middleware/binding and
FormRequests, resolves fresh trusted context, and executes its generated query
registration. It does not rerun the page controller. Root, related, and existence
queries receive visibility predicates. A browser cannot supply an authoritative AST.

Zero global scopes are removable by trusted application declarations, not immutable
write authorization. Inherited scopes register before attributes, then boot
registrations. Related scopes are independent: removing a parent scope does not
remove child scopes. Shared todo access follows current-user memberships, then
lists with a visible membership, then todos with a visible list; creator defaults
must not add owner-only list predicates. Keep policies, Gates, and association checks.

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

`withAttributes` adds equality conditions and create defaults unless
`asConditions: false` selects defaults only. Merge global defaults in registry
order, root/local defaults in call order, then explicit creation data. Predicate
and higher-order OR builders contribute conditions only, not parent defaults.
Global defaults cover Zero creates, including lowered controller creates and
helper create branches, never updates or ordinary Eloquent outside Zero. Fields
omitted from FormRequest `validated()` are not caller input; explicit trusted code
may override a default. Defaults are not immutable field authorization.

Arbitrary FormRequest hooks and Eloquent events do not automatically execute in
optimism. Choose explicit portable operations, custom optimism, or disabled
optimism for nonportable behavior instead of claiming arbitrary PHP parity.

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
