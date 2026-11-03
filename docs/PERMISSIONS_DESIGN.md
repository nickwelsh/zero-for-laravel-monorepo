# Policy visibility

Implementation is in progress. Normal Laravel Gates, the portable compiler and
PostgreSQL-backed Eloquent visibility are implemented. Zero integration and the
live error channel are not yet implemented.

## Laravel owns the application API

The target API uses normal Laravel policies and Gates. Collection visibility is an
explicit local `visibleTo($user)` scope, shared by Eloquent and `Model::zeroQuery()`.
There is no authentication-dependent global scope or second policy registration map.

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
unprefixed public-schema tables. Each source table has one binding; table aliases
and using the same table for subjects and resources are currently rejected.

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

These runs supplied projections directly. They prove native predicate feasibility,
not the correctness of the future Laravel compiler/projector or SSE access watches.
They do not measure production-scale performance or offline revocation, and cannot
erase data that a client already received legitimately.

Zero correlations are equality-only. Department equality uses a composite
correlation; mutable cross-row inequalities must fail compilation. Authorization
tables must replicate to Zero-cache even though they are absent from client schema
and results. In the bearer-authenticated fixture, Zero required both `ZERO_QUERY_URL`
and `ZERO_MUTATE_URL` to forward opaque credentials without a JWT verifier.
