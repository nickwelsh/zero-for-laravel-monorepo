# Query and mutation contracts

The public entry points are controller declarations, generated page contracts, and
generated actions. Models provide schema, casts, visibility, and writable columns.
Do not use model snapshot methods or named domain classes for new application code.
See the [main guide](../README.md) and [working example](../examples/todos/README.md).

## Visibility remains authoritative

`ZeroDefinition::authorize()` and `authorizeRelation()` constrain root reads,
includes, and relationship predicates. All relation targets must be registered.
`publicReads()` is an explicit public-data decision. The server resolves fresh
trusted context independently of the browser. Keep publication columns restricted
with Eloquent visibility and Zero column attributes.

Server scopes may add authoritative predicates not portable to the browser. They
must declare their relationship dependencies. An empty mandatory scope fails
closed. Model read visibility does not grant write permissions: use action
middleware, FormRequests, and explicit Gates/policies. Association IDs need their
own authorization checks.

## Checked query declarations

Use immutable `Model::zeroQuery()` declarations in a final `Inertia::render()` prop
array. Supported surrounding PHP includes typed route inputs, backed enums,
validated FormRequest fields, local assignments, boolean branches, comparisons,
and arrow callbacks. Unsupported guards and dependencies fail with source locations.
Arbitrary controller effects are not replayed on query transforms.

The ZQL construction surface targets Zero 1.11.3: comparisons, membership, patterns,
null tests, boolean expression groups, literal comparisons, correlated existence,
relationship refinement, ordering, raw cursors, limits, and singular results.
Existence supports `flip` and `scalar`; native scalar hints require unique-key
constraints. Sort direction may be literal or an asc/desc backed enum.

`infinite(size)` and `paginate(size)` must be the final operation on an unbounded
collection. They append a primary-key tie breaker when necessary. Infinite queries
grow one live window; cursor pages use raw storage values and reset when query or
identity changes. They do not promise total counts or snapshot pagination.

Directly returned bound models become live singular queries after Laravel binding.
Initial missing rows remain normal binding failures. Later unauthorized, deleted,
or missing singular rows become absent data rather than stale retained snapshots.

## Inputs, casts, and results

Generated Standard Schema-compatible validators initially use Zod through a driver
boundary. Public Date values are encoded before request identity or enqueueing.
FormRequests remain authoritative, including normalization and validation hooks.
Nested object/list rules must declare their types and bounds explicitly. Database
and custom validation rules are not invented as browser checks.

Plain rows decode primitive casts, backed enums, JSON, and dates recursively.
Native temporal columns use epoch milliseconds. Date-cast string columns use UTC
storage matching the model's `Y-m-d`, `Y-m-d H:i:s`, or `Y-m-d H:i:s.u` date format.
Unknown formats fail generation. Raw cursors retain precision beyond JavaScript
Date milliseconds. Arbitrary encrypted/custom PHP casts do not run in JavaScript.

## Generated actions use native transactions

`#[ZeroMutation]` explicitly registers a controller action. Generated mode lowers
checked reads, writes, branches, shared helpers, and result expressions. Custom mode
requires a projection; disabled mode makes no speculative writes. All modes use
native Zero queuing, replay, reconciliation, and result delivery.

Prepared IDs and time are stable across replay. Required bound rows are resolved
before optimistic writes; missing local data yields the declared no-op prediction.
Server binding and authorization still run. Late errors roll back the transaction.
`reloadForUpdate()` locks current state before state-dependent authorization.

`findUnique`, `insertUnlessExists`, and `upsertUnique` require real nonnullable,
unconditional unique keys. Reads see preceding writes. Conditional insertion never
overwrites an existing opt-out. A PostgreSQL insert conflict rolls back to a
savepoint and reloads the winner; explicit upserts update only named fields.

Bounded keyed loops require validated lists with distinct, nonempty stable string
keys and `max` at most 1000. Prepared UUID seeds plus item keys determine created
IDs. Traversal position never determines identity. Nested loops and repeated ULID
creation reject rather than producing unstable optimistic identities.

Return void or explicit JSON. Use `serverResult` and `clientResult` when inferred
contracts cannot express the intended shapes. The generated schemas preserve void
versus null and server versus speculative results. Native authoritative outcomes
persist atomically with writes/progress, including application errors. A lost HTTP
response does not require repeating application writes. Native result persistence
does not recreate JavaScript promise observers after a full page reload.

`Mutation::server()` marks server-only code. Replay never sends mail or dispatches
external jobs. Durable delivery requires application-owned outbox writes in the
same transaction plus an idempotent worker. Eloquent event handlers must observe
the same discipline, including events on a losing unique insert attempt.

## React and Inertia own presentation, not transport

Generated `Inertia.Pages` types follow component paths and include ordinary props and injected live
results. The page resolver adapter removes the need for per-prop query hooks.
`useLiveProps` exposes the same contract below the page; native Inertia `usePage`
continues to expose serializable props. No Zero rows enter page history or SSR props.

`useControllerMutation(action, routeArguments)` exposes local application,
authoritative acceptance, validation fields, and committed-result decoding errors.
Await its call for authoritative data. Identity changes and unmounts stop stale UI
updates, not already-enqueued writes. A result decode error after commit must not
cause automatic resubmission.

Deploy generated PHP registrations, schema, query bindings, mutation bindings, and
page types together. `zero:generate --check` detects drift. Unknown stale action
identities reject explicitly; retaining old queued operation versions is an
application deployment policy, not silent best-effort execution.
