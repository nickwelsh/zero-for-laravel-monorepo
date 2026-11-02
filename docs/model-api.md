# Query and mutation contracts

The public entry points are controller declarations, generated page contracts, and
generated actions. Models provide schema, casts, visibility, and writable columns.
See the [main guide](../README.md) and [working example](../examples/todos/README.md).

## Context, scopes, and visibility

Applications define a string-backed `App\Enums\ZeroContext` enum and configure
`context` and `context_resolver`. Exactly one public resolver method per case uses
`#[ZeroForLaravel\Attributes\ResolvesContext(ZeroContext::USER_ID)]`; native
`string`/`int`/`float`/`bool` and nullable return types define the contract.
Generation reflects signatures without executing methods. Laravel injects
`Request`/services when resolving trusted values at runtime into `ResolvedContext`.
Native `userID` and typed provider `context` are separate; neither invents an
application `user_id` key. Configured enum cases in supported builder value
positions become context references before domain enum flattening; `->value` is
always the literal backed value.

Protected `#[ZeroScope]` methods take `ZeroForLaravel\Query\ZeroQuery` and return
void or that same mutable builder. Direct `Todo::completed()` and
`Todo::zeroQuery()->completed()` compose the same scope, with dynamic arguments and
Eloquent-like higher-order `orWhere`. They are not Eloquent local scopes.

Global scopes implement
`ZeroForLaravel\Scopes\ZeroGlobalScope::apply(ZeroQuery $query, Model $model): void`.
Register with `#[ZeroScopedBy([ScopeClass::class])]`, an instance through
`static::addZeroGlobalScope`, or a named closure. Registration order is inherited,
attributes, then boot. Only the checked portable subset is supported. Remove scopes
by class/name with `withoutGlobalScope`, or all with `withoutGlobalScopes`;
removal drops conditions and defaults. Related scopes apply independently and can
be removed inside callbacks; parent removals do not propagate.

For shared todos, memberships are unscoped. Lists use `whereHas('memberships', ...)`
with a callback filtering `user_id` to current context, and todos require
`whereHas('list')`. Including memberships returns all collaborators on an accessible
list; standalone membership queries need explicit constraints if exposed. Lists set a creator
default with `withAttributes(['user_id' => ZeroContext::USER_ID], asConditions: false)`
so collaborator access is not narrowed to owners. These are removable read scopes,
not immutable write authorization. Keep policies, Gates, FormRequest association
checks, and narrow publication columns.

## Checked query declarations

Use mutable `Model::zeroQuery()` declarations in a final `Inertia::render()` prop
array; explicitly clone a base query for independent branches. This is a separate
Zero builder, not an Eloquent builder. Supported surrounding PHP includes typed
route inputs, backed enums, validated FormRequest fields, local assignments,
boolean branches, comparisons, and arrow callbacks. Unsupported guards and
dependencies fail with source locations.
Arbitrary controller effects are not replayed on query transforms.

The ZQL construction surface targets Zero 1.11.3: comparisons, membership, patterns,
null tests, boolean expression groups, literal comparisons, correlated existence,
relationship refinement, ordering, raw cursors, limits, and singular results.
Existence supports `flip` and `scalar`; native scalar hints require unique-key
constraints. Sort direction may be literal or an asc/desc backed enum.

The Laravel-facing API uses `with` for includes and `whereHas` for parent filters;
native `related`/`EXISTS` stay internal, without a `related()` alias. Relationships
are discovered from supported Eloquent methods between opted-in models.
`HasZeroQueries` enables discovery; `zero.models` explicitly registers alternatives.
There is no silent target enrollment or private-column expansion; unsupported
constraints fail. `$list->todos()` remains an Eloquent relation and cannot invoke
a Zero scope. Optional Scout metadata uses `public static zeroSearch(): ScoutSearch`;
Typesense authorization and index exposure remain separate from Zero scopes.

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

Arbitrary FormRequest hooks and Eloquent events are not automatically replayed in
optimism. Use custom or disabled optimism when the intended behavior depends on
nonportable PHP rather than claiming generated parity.

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

### Creation defaults

`withAttributes([...])` adds equality conditions and creation defaults by default;
`asConditions: false` supplies defaults only. Merge active global defaults in
registry order, then root/local defaults in call order, then explicit creation
data. Nested predicates and higher-order OR builders contribute conditions only,
not parent defaults. Related builders keep their defaults separate. Scope removal
removes that scope's conditions and defaults.

Global defaults apply to Zero creates, including compiler-lowered controller
`Todo::create($request->validated())` and helper create branches. Updates and
existing-row helper branches are never stamped. Ordinary Eloquent creates outside
Zero remain unchanged. A field absent from FormRequest `validated()` is not caller
input; trusted explicit creation data can override a default. These defaults are
not immutable authorization and do not replace policies or association checks.

See [the mutation guide](../apps/docs/content/docs/0.x/docs/mutations.mdx#creation-defaults)
for examples and [the 0.x migration mappings](../apps/docs/content/docs/0.x/docs/constraints.mdx#migrating-existing-0x-applications).
There is no `ZeroDefinition` compatibility layer or `related()` alias.

### Repeated creates, outcomes, and effects

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

Consumers own page types; no global page namespace is generated. Named query exports
support native Zero requests and optional decoded live selections. Infer input and
results with `InferQueryInput`, `InferQueryResult`, and `InferLiveQueryResult`.
The page resolver adapter removes the need for per-prop query hooks.
`useLiveProps` exposes the same contract below the page; native Inertia `usePage`
continues to expose serializable props. No Zero rows enter page history or SSR props.

### Editable query inputs

A controller's FormRequest defines its editable query inputs. Laravel seeds them
from the request URL and validates them again on every Zero transform. Route-model
bindings, route selectors, and authorization context are not editable filters.

```tsx
function Dashboard({ lists }: Inertia.Pages.Dashboard) {
    const { values, options, set, reset } = lists.inputs;
    // values.status: 'all' | 'active' | 'completed' | undefined
    // options.status: readonly ['all', 'active', 'completed']
    return <button onClick={() => set({ status: 'active' })}>Active</button>;
}
```

`set(partial)` shallow-merges fields; `set(current => partial)` uses the latest
values even during rapid updates. Set an optional field to `undefined` to remove
it; `null` is a distinct value and requires a nullable rule. Invalid inputs throw
before changing the subscription. Changed inputs reset pagination. `reset()`
restores the inputs supplied by the page, not a hard-coded default. Partial visits
that omit the live binding preserve local inputs; a new binding replaces them.
Queries without a FormRequest expose empty inputs and reject nonempty patches.
Date inputs here use canonical JSON strings, matching native Zero query arguments.

Finite options are inferred from direct `Rule::enum(BackedEnum::class)`, static
`Rule::in([...])`, and string `in:` validation rules. Values are also literal
unions in generated types. Options contain values, not presentation labels;
applications own labels and translations. Chained enum restrictions are not
supported; use an explicit static `Rule::in` list instead. Laravel remains
authoritative for validation and authorization, including dynamic rules.

Input changes only resubscribe to Zero by default; no Inertia request is needed.
Opt into URL synchronization explicitly:

```ts
const livePages = createInertiaAdapter(bindings, {
    url: { dashboard: { lists: { status: 'status' } } },
    history: 'replace', // or 'push' for navigable filter history
});
```

Only mapped scalar fields enter the URL. Undefined removes a parameter, null
becomes an empty value, and booleans use `1`/`0`. Inertia's client-side history API
stores serializable bindings, never live rows. Reloads use Laravel's normal
FormRequest parsing; use matching normalization for numeric/boolean URL inputs.
Native query exports still work with `zero.run`, `useQuery`, or `zero.materialize`.
Use `InferLiveQueryInputs`, `InferLiveQueryOptions`, and React's `QueryData<typeof
index.lists>` to derive types without a global namespace. The opt-in Wayfinder
integration uses `QueryData` in its own generated page declarations.

`useControllerMutation(action, routeArguments)` exposes local application,
authoritative acceptance, validation fields, and committed-result decoding errors.
Await its call for authoritative data. Identity changes and unmounts stop stale UI
updates, not already-enqueued writes. A result decode error after commit must not
cause automatic resubmission.

Deploy generated PHP registrations, schema, query bindings, mutation bindings, and
controller exports together. `zero:generate --check` detects drift. Unknown stale action
identities reject explicitly instead of executing a different contract.
