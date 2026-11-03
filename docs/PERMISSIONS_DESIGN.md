# Policy visibility

Normal Laravel Gates, the portable compiler, PostgreSQL-backed Eloquent visibility,
native Zero predicates, subscribed show errors, and optional current-user browser
predictions are implemented. The same policy protects REST and Zero; client
predictions and access-loss callbacks never authorize data delivery.

## Laravel owns the application API

The target API uses normal Laravel policies and Gates. Collection visibility is an
explicit local `visibleTo($user)` scope, shared by Eloquent and `Model::zeroQuery()`.
There is no second policy registration map. Models may opt into current-user
visibility with Laravel's `#[ScopedBy([VisibleToCurrentUser::class])]`; both builders
recognize this scope, including string-only relationship selections. The actor is
resolved at query execution, not boot. Missing actors fail closed. Explicit
`visibleTo($user)` replaces the default actor for that query; trusted Eloquent code
may remove the scope normally. Protected Zero paths still require a visibility
decision after removal. Global scoping also affects route binding (hidden rows
produce 404 before Gates) and bulk writes; it is not separate write authorization.

Schema publication uses an explicit `zero.models` allowlist or `HasZeroQueries`
discovery. Public models also require `zero.public_models`; a missing permission
binding never makes a model public. Creation defaults use `withAttributes()` with
typed application context; explicit creation attributes win, and defaults never
stamp updates or authorize writes. Scout search uses `zeroSearch()` or explicit
registration in an application provider. Column exposure rules remain unchanged.

Relationship dependencies are collected from supported declarations before final
schema generation. Private permission facts participate only in trusted database
publication, never the generated client schema or public model registrations.
Legacy `zero()` methods and `ZeroDefinition` are unsupported; there is no legacy
reader or replacement all-purpose model definition. Zero builders are mutable;
clone a base builder before constructing independent branches.

A portable policy returns `Casbin::inspect($user, 'view', $resource)`. The default
denial status is configurable; `asNotFound()` chooses 404 and `asNotAuthorized()`
chooses 403. A policy-level `before()` super-admin override is distinct from a Casbin
admin-role grant: the former bypasses matching denials, the latter remains subject
to the configured Casbin effect.

## Maintained Laravel collections

Models opt in with `ZeroForLaravel\Concerns\HasPolicyVisibility`. The local scope
`Model::query()->visibleTo($user)` resolves the ordinary Laravel `view` policy;
there is no second policy map. Visibility is applied with parameterized existence
predicates before counts and pagination. Caller filters stay outside the admin
override. An empty collection is a successful result.

Publish the permission migration/configuration, bind raw database attributes in
`permissions.resources` and `permissions.subject`, then run
`php artisan permissions:rebuild` against the application's PostgreSQL 15+ database.
This installs private resource facts, independent subject facts, effective grants
with source-rule provenance, and transactional database triggers. The backfill,
trigger installation, model fingerprints and revision events commit together.
Ordinary scalar Gates work without these projections or a running Zero-cache.

Managed Casbin operations, source mass updates and direct SQL subject/resource
writes maintain authority facts in the same transaction. Rule writes must use the
managed API; raw rule DML and source truncation are rejected. Transactions acquire
one advisory lock before model-row locks and source changes; managed writes and
rebuilds retry detected concurrency conflicts up to three times. An enclosing
application transaction should use Laravel's retry argument too. Rollback restores
business rows, grants, projections and durable invalidations together.

The initial projector recomputes effective grants on policy/role/subject changes,
bounded at 100,000 effective grants. It does not expand global grants per resource.
Resource projections upsert only changed facts and remove deleted keys. This favors
simple transactional correctness over large-graph throughput; applications must
measure their own write/fan-out costs. Role inheritance is acyclic and limited to
eight edges. All authority tables and source models must use one connection and
unprefixed public-schema tables. Each source table has one binding; custom table
aliases and using the same table for subjects and resources are currently rejected.
Laravel's generated self-relationship aliases preserve scoped existence queries.

Portable model equality is strictly typed, including scalar Gate evaluation through
PHP-Casbin. Numeric-looking string identifiers `"00042"` and `"42"` are different.
This deliberately avoids PHP's loose string equality, which would otherwise
disagree with SQL and browser checks. Wider, server-only Casbin models keep their
native evaluator semantics and cannot be used for portable collections.

After model/binding/schema changes, rebuild projections and regenerate compatible
Zero contracts before serving affected queries. Drain incompatible old streams;
the rebuild command is not a rolling schema-compatibility mechanism. Shared and
production migration/rebuild execution requires a separate deployment decision.

## Native Zero feasibility was exercised before integration

On 2026-11-03, real Zero 1.11.3 subscriptions exercised business rows, private
resource keys, effective grants, and independent subject facts. Five native clients
ran against disposable PostgreSQL and Zero-cache; no projection tables were in the
client schema. Two corrected runs, warm and fresh, each passed 55 checks with 30
replication barriers. Transform counters remained `requests:10, transforms:13`
throughout permission changes: no retransform, reconnect, or identity update.

The proven composition is:

```text
existing filters AND (live_admin OR (matching_allow AND NOT matching_deny))
```

The same procedure failed against the earlier, incorrect placement of the admin
branch beneath the denial predicate. With the corrected placement, an admin with
no normal allow bypassed a matching deny. Removing admin retained a surviving
ordinary allow, but revoked a user with only a deny. A filtered-only admin client
received seven document patches, all for its selected record; a missing-ID query
remained empty. Ordinary role grants remained subject to deny override.

Grant, owner, department, and admin changes updated existing subscriptions,
including overlapping and TTL-cached queries. Rolled-back changes did not leak
confidential values. Writes made after observed revocation reached an authorized
control but not the revoked client, checked against decoded WebSocket payloads
after same-transaction replication barriers. Private tables never appeared as
result rows or streamed row patches.

These early runs supplied projections directly. They prove native predicate
feasibility, independently of the subsequent Laravel compiler/projector and SSE
implementation. The maintained integration test also exercises managed grants
against unchanged native subscriptions; HTTP tests exercise actual access watches
with fresh actors and resources. Neither measures production-scale performance or
offline revocation, nor erases data a client already received legitimately.

Zero correlations are equality-only. Department equality uses a composite
correlation; mutable cross-row inequalities must fail compilation. Authorization
tables must replicate to Zero-cache even though they are absent from client schema
and results. In the bearer-authenticated fixture, Zero required both `ZERO_QUERY_URL`
and `ZERO_MUTATE_URL` to forward opaque credentials without a JWT verifier.

## Live access errors and advisory predictions

Supported unconditional instance `Gate::authorize()` calls protect initial shows
through normal Laravel exceptions. Generation captures the guard for server query
transforms and native predicates without replaying controller side effects.
Actor-owned watches use authenticated SSE, durable revision polling, and a fresh
Gate snapshot on every connection. The browser blocks protected content until
validated; confirmed loss unmounts it before `access.onRevoked`. Initial denial,
transport failure, and empty collections are not revocation events. Default denial
presentation is 403 or 404 according to configuration and policy overrides.

SSE polls committed revisions every 250 ms by default. Deploy without buffering
and with concurrent PHP workers; live means after commit and network/replication
delivery, not zero latency. Reconnect never trusts an old allow snapshot. Permission
row changes remain live; model/schema changes require rebuild, regeneration and
draining incompatible old streams.

The optional `/zero/permissions` endpoint is registered only when prediction
abilities are configured. It returns applicable effective grants for the
authenticated subject and explicitly allowlisted attributes, not raw role graphs.
`predictPermission` additionally requires the independently current identity and
returns unavailable for missing/private inputs. Predictions are advisory and must
be refreshed; REST, mutations, watches and native queries retain server authority.
