# Model contracts and React

Model attributes opt into `all`/`one` and mutation operations. One static
`zero(): ZeroDefinition` owns the public query contract, read constraints,
context-filled fields, and operation FormRequests. Legacy visibility/fill
attributes remain compatible; new code can keep these declarations in the definition.

## Read visibility and trusted context

```php
return ZeroDefinition::make()
    ->authorize('user_id', '=', ContextField::UserId)
    ->fillFromContext('user_id', ContextField::UserId)
    ->allowedFilters('title', AllowedFilter::exact('id'))
    ->allowedIncludes('list')
    ->allowedSorts('title', 'created_at');
```

Use `publicReads()` for explicitly public models, or `authorizeRelation('list')`
to require a related row visible under that model's own definition. These
constraints are generated for local queries and independently enforced by Laravel.
Context fills apply on create, are resolved separately on client/server, and are
excluded from caller-writable fields. Custom context keys can use string references.
The server context resolver must derive their values from authenticated state.

For runtime PHP, use a mandatory server scope:

```php
->enforceServerScope(
    function (ZQLBuilder $query, ZeroContext $context): void {
        $query->whereExists('list', fn (ZQLBuilder $list) => $list->where(
            fn (ZeroOperator $op) => $op->or(
                $op->cmp('user_id', $context->get('user_id')),
                $op->exists('shares', fn (ZQLBuilder $share) =>
                    $share->where('user_id', $context->get('user_id'))),
            ),
        ));
    },
    relations: ['list'],
)
```

Register `shares` on the list definition with `->relations('shares')` or through
its own scope's `relations` argument. This declares schema dependencies without allowing client
includes. Every related target must be registered and define its own visibility.
Scopes also apply to included rows and relationship predicates. Conditions within
a scope can use OR; the mandatory scope as a whole is ANDed with user filters.
An empty scope is an error. Missing required context denies access.

Callbacks execute with fresh trusted context during server query compilation,
never during generation. Their PHP source is not exported. Zero permits server
queries to add permission constraints absent from the local implementation.
Local queries can still match other already-synced authorized rows, so mirror
predicates with `authorize`/`authorizeRelation` when exact local behavior matters.

## Query-builder filters

`allowedFilters`, `allowedIncludes`, and `allowedSorts` accept variadic arguments
or one array. Disallowed arguments remain errors on both client and server.

```php
->allowedFilters(
    'title', // Escaped, case-insensitive substring.
    AllowedFilter::exact('id'),
    AllowedFilter::beginsWithStrict('prefix', 'title'),
    AllowedFilter::endsWithStrict('suffix', 'title'),
    AllowedFilter::operator('completed_after', FilterOperator::GREATER_THAN, 'completed_at'),
    AllowedFilter::operator('completed_at', FilterOperator::DYNAMIC),
    AllowedFilter::groupOr('search', [
        AllowedFilter::partial('title'),
        AllowedFilter::exact('id'),
    ]),
    AllowedFilter::callback('is_completed', fn (ZQLBuilder $query) =>
        $query->where('completed_at', 'IS NOT', null)),
)
```

Import `ZeroForLaravel\Enums\FilterOperator` and the builders from `ZeroForLaravel\Query`.
Scalar groups use one value across their members; use compatible column types.
`groupAnd` is also available. Unlike URL query strings, dynamic operators retain
the actual input type, including `Date`:

```ts
Task.query.all().filter({
    model: 'Task',
    name: 'completed_at',
    value: { operator: '>=', value: new Date('2026-01-01T00:00:00Z') },
});
```

Portable callback filters build a fixed predicate template during metadata
construction. An optional second callback argument represents its symbolic input:

```php
AllowedFilter::callback('title_is', fn (ZQLBuilder $query, mixed $value) =>
    $query->where('title', $value), 'title')
```

The last argument names the column defining the input type; without it, the input
is boolean. A requested callback applies its predicate, including when its value
is false. Use the input in builder comparisons; do not branch on it or call PHP
string/date functions on it. Runtime PHP/context belongs in `enforceServerScope`.
Callback relationship predicates, arbitrary Eloquent SQL/scopes, custom SQL sorts,
and Spatie's URL coercions are not supported. This API follows Spatie's naming
where ZQL supports the behavior; it is not an Eloquent builder replacement.

## Records, casts, and optional DTO types

```tsx
const tasks = useData(Task.query.all().with('list'));
const task = tasks.data[0];
// task.data.title
// task.data.list?.data.name
// useData(task.list) in a component receiving a defined task
```

Columns never collide with instance mutation methods. Included relations are
recursively hydrated snapshots. Lazy relations run through the parent's authorized
`one` query. They do not bypass visibility by querying a target table directly.

All supported database dates/timestamps, including implicit lifecycle fields,
become `Date`. Backed enum casts become backing-value literal unions. Boolean,
integer, float, string, array/JSON, and object casts hydrate their public values;
mutation preparation converts them back to native storage values. PHP DTO methods,
encrypted casts, and arbitrary custom cast transformations do not run in JavaScript.
JSON remains generic when no shape is declared.

A project can reuse a generated Laravel Data type or any handwritten DTO shape
through TypeScript's native module augmentation:

```ts
import type { PreferencesData } from './types';

declare module '@nickwelsh/zero-for-laravel' {
    interface ModelTypes {
        Task: { preferences: PreferencesData };
    }
}
```

The mapping refines rows and mutation inputs. It supplies static JSON typing,
not a runtime DTO constructor or validator. Authoritative nested validation still
belongs in Laravel. This does not require Laravel Data or TypeScript Transformer.

## Bound mutation forms and field permissions

```tsx
const { form, submit, defaultValues, state } = useMutation(task.update());

return (
    <form onSubmit={submit}>
        <input value={form.values.title ?? ''} onChange={(event) => form.setValue('title', event.target.value)} />
        <button disabled={state.status === 'pending'}>Save</button>
    </form>
);
```

The form exposes values, field errors, and column/writable-field metadata. It does
not prescribe a component library. Bound updates start with writable defaults
and submit edited fields plus any fields explicitly required by the update rules. `useMutation(task.destroy()).submit()` needs no ID.
The callable API remains available (`update({ title: 'Changed' })`), as do static
operations with explicit IDs. Bound IDs cannot be overridden. Reset restores defaults;
switching record or Zero identity clears form state and ignores old mutation outcomes.

Policies decide whether a user may perform an operation on the original record.
An update FormRequest can then restrict which fields they may submit:

```php
public function authorize(): bool
{
    $task = $this->route('model');
    $user = $this->user();
    if (! $task instanceof Task || $user === null) {
        return false;
    }
    $allowed = $task->user_id === $user->getAuthIdentifier()
        ? ['title', 'completed_at']
        : ['completed_at'];

    return array_diff(array_keys($this->except('id')), $allowed) === [];
}
```

The policy must separately deny users without task access. A patch containing
any forbidden field is rejected as a whole. Keep `rules()` extractable without
runtime request data; request-specific permissions belong in `authorize()`.
Normalization hooks are trusted server code: they must preserve these field
restrictions if they introduce additional values after authorization.

## Nested mutations

Declare which child operations are allowed under each parent operation:

```php
use ZeroForLaravel\Definition\NestedMutation;

return ZeroDefinition::make()
    // Existing visibility, context fills, and FormRequest declarations go here.
    ->allowedNestedMutations('create',
        NestedMutation::create('person'),
    )
    ->allowedNestedMutations('update',
        NestedMutation::create('person'),
        NestedMutation::update('person'),
    );
```

The relation must be a registered Eloquent `belongsTo`, `hasOne`, or `hasMany`
relation. Read includes and nested writes have separate allowlists. Every nested
edge needs an explicit declaration; allowing `person` does not also allow its
relations. Child models must be registered, but need no `ZeroQuery` or `ZeroMutate`
attributes. Configure their `mutationRequests()` normally even when their
operations are only reachable through a parent. Nested creation requires the same
UUID/ULID key generation as standalone creation.

### Authorization

By default, Laravel checks the parent policy for the root action and the child's
own policy for its nested action. Creating a Party with a Person checks
`PartyPolicy::create($user)` and `PersonPolicy::create($user)`. Updating checks
each policy against its existing record. Missing or denying policies reject the
entire mutation. Parent update authorization also applies to relation-only patches.

An optional ability adds a relationship check on the parent policy:

```php
NestedMutation::create('person')->authorize('addPerson');
// PartyPolicy::addPerson(User $user, Party $party)

NestedMutation::update('person')->authorize('updatePerson');
// PartyPolicy::updatePerson(User $user, Party $party, Person $person)
```

To explicitly let the parent's authorization grant a nested operation:

```php
NestedMutation::create('person')->authorizeUsingParent();
NestedMutation::update('person')->authorizeUsingParent();
```

This skips the child's policy check. Its FormRequest authorization and validation
still run. An additional `authorize()` ability, if configured, still runs too.
Inheritance uses the immediate parent's successful authorization, so it can
continue through explicitly declared descendants.

Each FormRequest receives its own model's fields and `id`, its authenticated user,
and `route('model')` on the HTTP endpoint. It receives no nested relation payloads.
Access trusted nesting context through:

```php
use ZeroForLaravel\Mutation\MutationContext;

$mutation = MutationContext::from($this);
$mutation->operation; // 'create' or 'update'
$mutation->parent;    // The immediate Eloquent parent, or null for the root.
$mutation->relation;  // 'person', or null for the root.
```

When the child is a `belongsTo` dependency, it must be saved before the parent.
During those child checks, a newly created parent is still unsaved; it has its
prepared ID and trusted context fills. Policies must account for that. For
`hasOne`/`hasMany`, the parent is already saved within the transaction. Generated
contracts contain allowed operations, not policy implementations or decisions.

### Client and forms

```tsx
const { form, submit } = useMutation(Party.create().with('person'));

form.setValue('name', 'Birthday');
form.setValue('person.first_name', 'Nick');

return <Form form={form} onSubmit={submit} />;
```

`form.fields` and `form.columns` include prefixed paths such as
`person.first_name`; collections use templates such as `people.*.first_name`.
Set collection values through concrete paths like `people.0.first_name` or set
the relation's whole array. Values are nested objects, and errors use the same
concrete dot paths. Relation keys supplied by the executor are excluded from
writable form fields and generated mutation input types.

Direct submission uses the same nested shape:

```ts
const create = useMutation(Party.create().with('person'));
create({ name: 'Birthday', person: { first_name: 'Nick' } });

const update = useMutation(party.update().with('person'));
update({ person: { id: person.data.id, first_name: 'Updated' } });

// Explicitly create a child while updating its parent:
const add = useMutation(party.update().with('person', 'create'));
add({ person: { first_name: 'New' } });

// Configure deeper selections on the child operation:
Party.create().with('person', 'create', (person) => person.with('addresses'));
```

`.with(name)` uses the parent's operation. An explicit second argument chooses
another allowed child operation. One selected operation applies to all submitted
records for that relation; creation and update are never inferred from an ID.
Only declared combinations and model fields are accepted, in TypeScript and on
the server. Included snapshots supply defaults for bound nested updates. Bound
update forms submit changed fields plus fields explicitly required by each
model's update rules, preserving child IDs.

### Execution guarantees and limits

One invocation is one native Zero transaction and one Laravel database
transaction. IDs and optimistic timestamps are prepared once before enqueueing.
`belongsTo` dependencies are saved before their parent; `hasOne`/`hasMany`
children are saved after it. Any failure rolls back every application write.
External side effects from model events must run after commit.

Linking keys are derived from registered relationship metadata. Callers cannot
supply them alongside nested operations, and FormRequests or model events cannot
rewrite them. Creates receive the derived keys for validation. Existing-child
updates verify membership without adding those unchanged keys to their request
input. Updates lock the existing records and reject unrelated child IDs.

Omitting a relation leaves it alone. An empty collection performs no child writes;
`null` is rejected. Creating through an occupied `hasOne` or `belongsTo` relation
is rejected instead of replacing its existing record. Keep database foreign keys
and unique constraints for `hasOne` relationships so these invariants also hold
for writes outside this API.

Nested operations currently support create and update through direct relations
using primary-key references. Each graph is limited to eight relation levels and
100 records, including the root; the same record cannot appear twice. Connect,
disconnect, reparenting, nested deletion, many-to-many, and polymorphic writes are
not supported.

## Optional Scout and InstantSearch integration

Scout remains responsible for indexing. A model can opt into generated public search metadata with
`ZeroDefinition::make()->search(ScoutSearch::for(SearchDocument::class))`.
The integration reads the effective Typesense collection schema (the model override takes precedence)
and Scout's `search-parameters.query_by`. Facets default to eligible schema facets; `facets(...)`,
`filters(...)` and `sorts(...)` narrow the public controls. `key` on `ScoutSearch::for` identifies the
search document field containing the canonical Zero model ID. Credentials and scope closures never
appear in generated definitions.

`ScoutSearchProvider` from `@nickwelsh/zero-for-laravel/react/search` uses the official Typesense
InstantSearch adapter and accepts a generated model's `search` metadata and an authenticated
`multi_search` endpoint. Ordinary InstantSearch hooks and widgets can consume the same provider.
`useScoutHits` returns identifiers, engine counts, pagination, response scope, and search status.
Use an approved generated selection such as `.filter('ids', hits.ids)` to hydrate records; use
`rankSearchRows` to retain engine order and omit missing or unauthorized rows after Zero completes.
Search counts describe the index, rather than the number of hydrated records.

Register `ScoutSearchController` behind session authentication and rate limiting. Each transported
model requires an explicit `scope(fn (ZeroContext $context): string => ...)` Typesense filter.
The transport validates bounded expressions and approved fields, facets and sorts, enforces its
scope, and projects hits to the hydration key with empty highlights. It does not translate arbitrary
Eloquent authorization into search predicates. A custom browser search client may instead use a
properly scoped search-only key; its key must enforce projection and protect facet/count metadata.

The optional `createSearchGrammar` helper derives field operators and suggestions from metadata.
Applications declare product aliases, fixed values such as `is:starred`, and date vocabulary.
Plain text remains the engine query; structured terms compile to Typesense filters.

## Bounded composable filters

`AllowedFilter::in('ids', 'id', max: 1000)` declares bounded scalar sets, including an empty set.
`AllowedFilter::related('label', 'labels', 'name')` declares a scalar comparison inside an authorized
relationship. `AllowedFilter::conditions('rules', [...], max: 10)` accepts a bounded
`{match: 'all' | 'any', rules: [{field, value}]}` group containing only named approved filters.
Existing scalar groups can be members. `AllowedFilter::pattern` adds a trusted prefix/suffix around
an escaped string input for portable patterns. Client types and PHP compilation share these semantics;
server scopes and related-model authorization always remain authoritative.

## Shared operation execution and forms

Model CRUD (`Post.create()`, `post.update()`) and named actions (`actions.save()`) expose
`.prepare(input)`, `.execute(zero, input, {signal})`, `.form.definition`, and
`.createForm(zero, options)`. Native Zero owns durable mutation submission and replay.
`execute` waits for authoritative server acceptance; CRUD returns `{id}` from the prepared request.
Named operations generate their form metadata from the wire contract and portable FormRequest rules.

`createForm` produces a FormBraid descriptor. Options include `bind` for fixed hidden input,
`only` to choose fields, `defaultValues`, and presentation overrides in `fields`. Bound input wins
over submitted values. Generated constraints and server rules are retained. Cancelling a form stops
observation; an already submitted native mutation remains durable. Successful results carry
`value: {resolved: true, data}` with typed data, allowing the application's action lifecycle to own navigation,
toasts, and other success effects.

A named action can optionally declare `output()` with the same bounded scalar field syntax as
`input()` and implement the read-only `result($input, ZeroContext $context)` resolver. The generated
operation resolves this result after native acceptance through authenticated `POST /zero/results/{operation}`
(with the configured route prefix). The endpoint reapplies input validation and FormRequest authorization;
the resolver must apply the same trusted owner/tenant scope as the write. It returns only the declared
output shape. For example, a URL-deduplicating save can normalize the original input on the server and
resolve its canonical record ID. No result is exposed by default, and the read never reruns `handle()`.
The browser uses the same-origin session and Laravel's XSRF cookie; applications using a different auth
transport can call `prepare` and integrate their own result read.

The resolver is a subsequent authorized read, not a snapshot of the original transaction. It must be
safe to repeat; concurrent changes or deletion can make a result unavailable. If a committed operation's
result read fails, `execute` throws `MutationResultError` with `committed: true`; its form reports success
with `value: {resolved: false, error}`. Retry `resolveResult(input)`, rather than repeating the write.
`completeMutation` and `nativeMutationForm` remain low-level bridges for custom native mutators.
