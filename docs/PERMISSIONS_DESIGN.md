# Policy visibility

Implementation is in progress. This document records the native feasibility boundary;
it does not claim that the Laravel compiler, projections, or live error channel exist yet.

## Laravel owns the application API

The target API uses normal Laravel policies and Gates. Collection visibility is an
explicit local `visibleTo($user)` scope, shared by Eloquent and `Model::zeroQuery()`.
There is no authentication-dependent global scope or second policy registration map.

A portable policy returns `Casbin::inspect($user, 'view', $resource)`. The default
denial status is configurable; `asNotFound()` chooses 404 and `asNotAuthorized()`
chooses 403. A policy-level `before()` super-admin override is distinct from a Casbin
admin-role grant: the former bypasses matching denials, the latter remains subject
to the configured Casbin effect.

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
