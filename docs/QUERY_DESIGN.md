# Zero for Laravel: Query Design

Status: historical design baseline, implemented in the v0.5 controller-first release.

This document specifies the query side of the proposed controller-first Zero integration. It records the intended developer experience, ownership boundaries, generated contracts, runtime behavior, and requirements for proving compatibility.

This document records the original design, not the current API reference. Examples
and acceptance requirements below retain their original proposed spellings.
In particular, Zero does not generate a global page namespace: applications own
their types, or opt into Wayfinder's page generator. Current native query factories,
decoded `QueryData` props, editable FormRequest inputs, URL synchronization, and
supported constraints are documented in [the contract guide](model-api.md) and
[the React guide](../apps/docs/content/docs/0.x/docs/react.mdx).

## 1. Agreed decisions

| Area                | Decision                                                                                                                                                                                                       |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Query ownership     | Declare page queries in Laravel controllers alongside ordinary Inertia props.                                                                                                                                  |
| ZQL compatibility   | Implement the complete query-construction and expression surface of a supported, explicitly identified Zero version in PHP.                                                                                    |
| Execution           | Zero continues to execute queries and own synchronization, subscriptions, and caching.                                                                                                                         |
| Inertia payload     | A Zero-backed prop is absent from serialized page props, including the initial HTML page object. It is not serialized as `null`, an empty collection, a query descriptor under the same key, or database rows. |
| React API           | The integration automatically injects a typed live-query object into the page component. Pages do not manually call `useQuery()` for declared props.                                                           |
| Discovery           | Discover registered routes and analyze controller responses using the Ranger/Surveyor foundation used by Wayfinder's `next` branch.                                                                            |
| Query generation    | Generate native TypeScript ZQL and an authoritative PHP query registration from one analyzed query definition.                                                                                                 |
| Input validation    | Generate Standard Schema-compatible validators, initially using Zod. Keep the schema-generation boundary driver-based.                                                                                         |
| Casts               | Use model metadata to encode query arguments and decode results. Date-cast fields expose JavaScript `Date` values.                                                                                             |
| Enums               | Reuse Wayfinder `next` enum types and runtime value objects when available.                                                                                                                                    |
| Route model binding | Keep normal Laravel binding and its database lookup. Convert eligible returned bound models into live query props after normal controller execution.                                                           |
| Shared components   | A page normally consumes one route/action's generated prop contract. Sharing a component across routes with the same contract is supported.                                                                    |
| PHP analysis        | Support a defined, useful subset of PHP around query declarations. Full ZQL compatibility does not imply arbitrary PHP-to-TypeScript compilation.                                                              |
| Security            | Server query registration, validation, and authorization remain authoritative for every query invocation.                                                                                                      |

Mutations are outside this document. Nothing here specifies their transport, optimistic behavior, controller dispatch, or result contracts.

## 2. Controller and page experience

### 2.1 A normal live collection

```php
use App\Models\Person;
use Inertia\Inertia;
use Inertia\Response;

public function index(): Response
{
    return Inertia::render('people/index', [
        'title' => 'People',
        'people' => Person::zeroQuery()
            ->orderBy('last_name', 'asc')
            ->orderBy('id', 'asc'),
    ]);
}
```

Constructing this query does not execute SQL or retrieve people in PHP. It creates a query declaration. The ordinary `title` prop follows normal Inertia behavior.

The page receives the live result automatically:

```tsx
export default function People({ title, people }: Inertia.Pages.People.Index) {
    if (people.status === 'loading') {
        return <p role="status">Loading people…</p>;
    }

    if (people.status === 'failure') {
        return <p role="alert">Unable to load people.</p>;
    }

    return (
        <main>
            <h1>{title}</h1>
            <ul>
                {people.data.map((person) => (
                    <li key={person.id}>
                        {person.first_name} {person.last_name}
                    </li>
                ))}
            </ul>
        </main>
    );
}
```

The namespace above follows the sketch's desired usage. The integration must coordinate with the supported Wayfinder generator rather than independently emitting conflicting declarations. Types are associated with the route/action's response contract, regardless of their final emitted namespace.

### 2.2 Infinite loading

```php
public function index(): Response
{
    return Inertia::render('people/index', [
        'people' => Person::zeroQuery()
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->infinite(25),
    ]);
}
```

```tsx
export default function People({ people }: Inertia.Pages.People.Index) {
    return (
        <main>
            <ul>
                {people.data.map((person) => (
                    <li key={person.id}>{person.first_name}</li>
                ))}
            </ul>

            <button type="button" onClick={people.loadMore} disabled={people.hasMore !== true || people.isLoadingMore}>
                {people.isLoadingMore ? 'Loading…' : 'Load more'}
            </button>
        </main>
    );
}
```

The original `->all()->infinite()` spelling may be retained as convenience syntax. Collection cardinality is already the default in native ZQL; `all()` must not execute the query or silently mean an unbounded result. It must not install a hidden limit that changes native query semantics. Pagination helpers own their explicit window sizes.

### 2.3 A single record

Explicit query declarations support native `.one()` semantics:

```php
public function show(string $personId): Response
{
    return Inertia::render('people/show', [
        'person' => Person::zeroQuery()
            ->where('id', $personId)
            ->one(),
    ]);
}
```

The preferred Laravel convention is normal model binding:

```php
public function show(Person $person): Response
{
    return Inertia::render('people/show', [
        'person' => $person,
    ]);
}
```

The bound-model convention and its security constraints are specified in section 9.

## 3. Full ZQL compatibility

### 3.1 Scope of the compatibility promise

The PHP API must represent every public query-construction and expression operation in the supported native ZQL version, including overloads and options. Supporting only the current package's allowlisted filter API would not satisfy this requirement.

The inventory includes, but is not limited to:

| Capability           | Required semantics                                                                                                          |
| -------------------- | --------------------------------------------------------------------------------------------------------------------------- |
| Column comparisons   | Equality shorthand and explicit operators, with the native operand restrictions.                                            |
| Operators            | The full native operator set, including membership, string matching, null tests, and their negations.                       |
| Expressions          | `cmp`, `cmpLit`, `and`, `or`, `not`, and nested expression factories.                                                       |
| Relationship filters | `whereExists`, expression-level `exists`, nested refinements, and supported options.                                        |
| Related results      | `related`, nested related queries, relationship cardinality, and callback refinements.                                      |
| Ordering             | Multiple order terms and native ordering behavior.                                                                          |
| Bounds               | `limit`, `start`, and inclusive/exclusive cursor behavior.                                                                  |
| Cardinality          | Collection results and `.one()`, including no-match behavior.                                                               |
| Parameters           | Literal values, runtime arguments, and native context/static parameter references where exposed by the supported version.   |
| Schema behavior      | Relationship paths, column mappings, composite identity where supported, and the schema constraints required by native ZQL. |

This table is a guide, not a substitute for auditing the actual upstream public API. New upstream methods or options require an explicit compatibility update.

ZQL runtime operations such as materialization, preloading, and running client queries stay with the native client. The PHP declaration builder does not reproduce Zero's client runtime. Laravel helpers may improve ergonomics, but must compile to the same query semantics.

### 3.2 Expressions and related data

The PHP form should remain recognizable to someone familiar with native ZQL:

```php
use ZeroForLaravel\Query\ExpressionBuilder;
use ZeroForLaravel\Query\Query;

$query = Person::zeroQuery()
    ->where(fn (ExpressionBuilder $expression) => $expression->and(
        $expression->cmp('status', '=', PersonStatus::Active),
        $expression->or(
            $expression->cmp('first_name', 'ILIKE', 'ann%'),
            $expression->cmp('last_name', 'ILIKE', 'ann%'),
        ),
    ))
    ->whereExists('notes', fn (Query $notes) => $notes
        ->where('pinned', true))
    ->related('notes', fn (Query $notes) => $notes
        ->orderBy('created_at', 'desc')
        ->orderBy('id', 'desc')
        ->limit(5))
    ->orderBy('id', 'asc');
```

The example uses proposed builder class names and assumes a `notes` relationship and a `PersonStatus::Active` case. Native operator spellings are canonical. Any accepted Laravel-style aliases, such as lowercase `like`, must normalize explicitly without changing behavior.

### 3.3 Immutability and missing values

Builder operations return new query values, matching native ZQL's compositional model. Reusing a base query must not accidentally modify another declaration:

```php
$baseQuery = Person::zeroQuery()->where('status', PersonStatus::Active);

$ascending = $baseQuery->orderBy('created_at', 'asc');
$descending = $baseQuery->orderBy('created_at', 'desc');
```

PHP has no JavaScript `undefined` value. The internal representation therefore needs a distinct missing-value state where native semantics require one. It must not collapse absent input, explicit `null`, and an ordinary scalar into the same value. Public helper names for expressing optional operands remain an API spelling detail.

### 3.4 Versioning

Record the supported `@rocicorp/zero` version and query protocol/AST assumptions in the implementation. The existing package inspected during design uses Zero 1.10.0; this is a research baseline, not a promise that the new integration will target that version forever.

PHP compilation targets the representation required by that supported version. Compatibility tests must detect changes to relationship compilation, cursor handling, expression normalization, or protocol shapes. Do not depend on undocumented internals without identifying and testing that dependency.

If a schema or operand combination is unsupported by native ZQL, reject it clearly. Do not emulate unsupported SQL features or silently fall back to fetching data through Eloquent.

## 4. Discovery, analysis, and generated query definitions

### 4.1 Reuse the Laravel analysis foundation

Wayfinder's `next` branch uses Ranger to discover application components and Surveyor for analysis. Ranger identifies possible Inertia responses; Wayfinder converts response data to TypeScript and generates route component helpers.

Use that foundation to discover registered routes, controller methods, response component names, model metadata, enum metadata, and validation contracts. Do not build a second route scanner or parse controller source with regular expressions.

A declared `Inertia\Response` return type is helpful but not mandatory. Analyze actual possible return expressions; methods in the sketch have no declared return types. A declared response type alone does not identify its component or query props.

Surveyor's general type inference is not a complete query compiler. The integration must additionally understand the query-building expression and the data dependencies that produce it. Its extension mechanism must be verified against the chosen version; this document does not assume a particular unverified hook.

### 4.2 One semantic definition

The analyzed representation describes:

- The action and response component associated with the declaration.
- The prop name, root model/table, and result cardinality.
- Query operations, expression trees, related results, and cursor behavior.
- Input names, sources, types, validation constraints, and normalization.
- Supported conditions and transformations that build the query.
- Trusted server context dependencies and visibility rules.
- Result casts and included relationship shapes.
- Infinite or paginated presentation settings, when requested.

It must retain query construction and the necessary input expressions, not just a precomputed AST for one request. Otherwise changing input would not reconstruct the same query on the client.

From this representation, generate native TypeScript ZQL for local execution and an authoritative PHP query registration for Zero's server query-transform endpoint. Validation schemas and frontend prop types derive from the same contract.

The implementation can reuse equivalent existing definitions, codecs, and lifecycle code. There must not be two independently maintained descriptions of the same page query.

### 4.3 Separate HTTP execution from query execution

During a normal page visit, Laravel executes the original controller. Its query objects are declarations that the integration extracts before serialization.

During a Zero query invocation, the server executes the registered query definition with validated arguments and current authenticated context. It does not rerun the full HTTP controller, render an Inertia response, or repeat unrelated controller side effects.

Codegen must therefore extract the query's complete semantic dependency set. A guard or scope that affects access cannot be discarded merely because it appears before the builder chain. If the analyzer cannot safely represent the dependency, generation must fail or require an explicit supported declaration.

Controller actions are not executed during generation to discover sample values. Ranger/Surveyor may still boot Laravel, inspect container bindings, and inspect model/database metadata. Builds must account for their documented behavior and avoid pretending the analysis is completely independent of the application environment.

### 4.4 Generated identities and shared components

A stable internal identity can be based on the controller action and prop name, with route-specific binding metadata when needed. The exact identifier format is private to the integration.

A component normally consumes the generated props associated with its route/action. If several routes return the same prop contract, they may share the component. Each visit still selects the actual query and arguments associated with that response; matching TypeScript shapes do not imply identical runtime input.

Do not introduce automatic heterogeneous page unions, a component-level query router, or rendering guards merely to accommodate unrelated contracts. Application authors can explicitly compose types if they choose that architecture, but it is not the default design. Prefer separate components when response contracts differ materially.

An ambiguous query declaration for the same action and prop must be reported unless the supported analysis can represent it correctly. Full ZQL coverage does not require guessing which arbitrary PHP return branch will execute.

## 5. Input parameters and supported PHP

### 5.1 Route parameters and enum binding

```php
use App\Enums\SortDirection;

public function indexByType(string $type, SortDirection $order): Response
{
    return Inertia::render('people/index', [
        'people' => Person::zeroQuery()
            ->where('type', $type)
            ->orderBy('created_at', $order)
            ->orderBy('id', $order)
            ->infinite(25),
    ]);
}
```

Normal Laravel parameter and backed-enum binding runs for the page request. The generated contract identifies `$type` and `$order` as inputs. Enum values become their supported scalar representation before reaching native ZQL.

Binding metadata must relate actual route placeholders to action parameters. Do not infer arguments by manually splitting the browser URL, especially where domains, defaults, optional parameters, encoding, or several routes per action are involved.

The page response provides the authoritative resolved binding arguments. During an instant visit, generated route information can supply arguments early only where the mapping and normalization are known. Otherwise the wrapper waits for the server response before subscribing.

### 5.2 FormRequest inputs and conditional queries

```php
use App\Http\Requests\PersonIndexRequest;

public function index(PersonIndexRequest $input): Response
{
    $validated = $input->validated();
    $query = Person::zeroQuery();

    if (isset($validated['first_name']) && $validated['first_name'] !== '') {
        $query = $query->where(
            'first_name',
            'LIKE',
            '%'.$validated['first_name'].'%',
        );
    }

    if (isset($validated['last_name']) && $validated['last_name'] !== '') {
        $query = $query->where(
            'last_name',
            'LIKE',
            '%'.$validated['last_name'].'%',
        );
    }

    return Inertia::render('people/index', [
        'people' => $query
            ->orderBy('id', 'asc')
            ->infinite(25),
    ]);
}
```

`validated()` returns an array, so examples use array access. This example deliberately includes the string `"0"` as a filter value; it does not rely on PHP truthiness.

The existing request rules are sufficient to describe the public shape:

```php
public function rules(): array
{
    return [
        'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
    ];
}
```

The client schema preserves optionality and nullability. The query compiler preserves the branches and concatenation. Laravel's request preparation and normalization, including empty-string handling, must be represented consistently where relevant; generating only the validation rules is not sufficient to reproduce normalization.

### 5.3 The supported analysis boundary

The initial analysis target includes typed action parameters, known FormRequest access, local assignments, builder reassignment, literals, supported array access, enum constants, supported comparisons, boolean composition, conditional branches, expression callbacks, and string concatenation.

Each construct needs an explicit semantic implementation. Do not translate PHP coercion, truthiness, equality, missing keys, or string operations by assuming they are identical to JavaScript.

Potential conveniences such as `whenPresent()` and `whenFilled()` may express common conditional patterns more directly. Their spelling and signatures remain open. They must define the treatment of missing values, `null`, empty strings, zero, and false, rather than relying on an ambiguous notion of truthiness.

Arbitrary application service calls, database reads used to construct a query, side-effecting callbacks, custom casts implemented in PHP, and unconstrained loops are not automatically portable. A supported explicit declaration or server-resolved scalar input may handle a particular case. Otherwise generation reports the unsupported dependency with its file, action, prop, and source location. It never silently drops a branch or falls back to `any` for an unanalyzed query.

### 5.4 Input ownership

Distinguish public arguments from trusted context. A route ID or filter is untrusted input even if it arrived in a page manifest. The current user, tenant, permissions, and server-enforced constraints come from the authenticated server context, not client-supplied fields.

Server-resolved values that are safe to expose may be supplied as normalized arguments. Secrets and authorization-only state stay server-side. Capturing an authorization decision at page-render time must not replace authorization during later query invocations.

Changing a route or FormRequest-backed filter normally uses the existing Inertia/Wayfinder visit flow. The new response supplies updated arguments and the wrapper replaces the subscription automatically. Manual generated query functions can also accept typed arguments for use outside page injection; a separate reactive page-filter setter API is not required by this design.

## 6. Schema generation and argument encoding

### 6.1 Validator-independent contracts, Zod output

The internal input contract is independent of the selected validator library. A generation driver emits schemas and supporting imports from that contract. Zod is the initial implementation; another driver can emit a different Standard Schema-compatible validator without changing controller declarations or the PHP query compiler.

Keep this extension point at generation time. Do not add a runtime validator registry or implement a replacement validation engine. Standard Schema alone does not standardize bidirectional encoding, so the generated binding must also expose the conversion functions required by the contract.

For the filter request above, a representative Zod output is:

```ts
import { z } from 'zod';

export const peopleInput = z.object({
    first_name: z.string().max(255).nullable().optional(),
    last_name: z.string().max(255).nullable().optional(),
});

export type PeopleInput = z.input<typeof peopleInput>;
```

This illustrates shape, not an assertion that every Laravel rule has an exactly equivalent Zod expression. For example, PHP/Laravel and JavaScript can count Unicode string lengths differently. Translation must preserve the intended rule or document the client check as advisory; the authoritative server rule still runs.

Generation must explicitly choose its unknown-key behavior and normalization. The query request contract must not accidentally widen because a validator happens to strip or preserve extra object properties by default.

### 6.2 Authoritative validation

Validate arguments on the server for direct query-transform calls as well as ordinary page visits. Generated client validation improves errors and typing; it does not establish trust.

Database validation rules, arbitrary custom rules, and FormRequest authorization execute in PHP. Do not claim that their absence from the client schema is equivalent validation. Unsupported validation translations should be identified in generation diagnostics, without silently removing server enforcement.

The query handler must receive enough authenticated request/binding context to run its required authorization and validation. Reuse existing enforcement where it fits. If an HTTP-dependent FormRequest cannot be faithfully used by the query handler, require a supported explicit query contract rather than calling its methods with fabricated context.

The inspected Zero implementation requires synchronous Standard Schema validation. Generated validators used directly by Zero must not perform async refinement or network requests.

### 6.3 Public arguments and wire arguments

Public arguments may contain richer application values, including `Date`. Native query requests, query identities, and wire payloads use normalized, JSON-compatible arguments.

The sequence is:

```text
Application arguments
  -> public validation
  -> cast-aware encoding and normalization
  -> native Zero query request with wire arguments
  -> native Standard Schema validation
  -> native ZQL construction
```

The PHP handler validates the wire contract and interprets values consistently. Optional keys are normalized consistently so equivalent inputs do not acquire accidental different query identities. Arrays and cursor values preserve meaningful ordering.

The generated public binding must encode before constructing the native query request. Zero's inspected query implementation retains original arguments for named query identity and transport even when its validator produces a transformed value. A date transform inside `defineQuery()` alone is therefore insufficient.

An abbreviated generated binding illustrates the boundary:

```ts
import { defineQueries, defineQuery } from '@rocicorp/zero';
import { z } from 'zod';

const publicInput = z.object({
    createdAfter: z.date(),
});

const wireInput = z.object({
    createdAfter: z.number().int().min(-8640000000000000).max(8640000000000000),
});

const nativeQueries = defineQueries({
    peopleCreatedAfter: defineQuery(wireInput, ({ args }) =>
        zql.people.where('created_at', '>=', args.createdAfter).orderBy('created_at', 'asc').orderBy('id', 'asc'),
    ),
});

export function peopleCreatedAfter(input: z.input<typeof publicInput>) {
    const validated = publicInput.parse(input);
    const encoded = wireInput.parse({
        createdAfter: validated.createdAfter.getTime(),
    });

    return nativeQueries.peopleCreatedAfter(encoded);
}
```

Here `zql` is the generated native schema query builder, supplied by the generated module. The example assumes a native timestamp represented as epoch milliseconds; other storage types require their own encoding. Generation can use shared codecs rather than emitting this conversion inline for every query.

## 7. Model casts and result decoding

### 7.1 Separate storage types from public types

Model metadata describes both the raw replicated column representation and the desired application representation. Do not change the native Zero schema to pretend a replicated scalar is a JavaScript `Date` object.

```php
protected function casts(): array
{
    return [
        'created_at' => 'immutable_datetime',
        'updated_at' => 'datetime',
        'birthday' => 'date',
        'status' => PersonStatus::class,
    ];
}
```

The corresponding public result shape can be:

```ts
type Person = {
    id: string;
    first_name: string;
    last_name: string;
    created_at: Date;
    updated_at: Date;
    birthday: Date | null;
    status: PersonStatus;
};
```

The example assumes string IDs. Actual key types come from model/schema metadata. Nullability also comes from that metadata, not from the cast name alone.

Decode after Zero supplies raw results and before exposing `.data` to the page. Apply decoding recursively to included relationships, respecting their to-one/to-many cardinality and missing-value behavior. Keep decoding stable across renders and avoid rebuilding every row when its raw value has not changed.

Decoded dates are presentation values. Applications must not mutate them expecting a database update. PHP's mutable versus immutable date casts do not imply that JavaScript `Date` itself becomes immutable.

### 7.2 Date encoding rules

Standard Laravel date and datetime casts, including their immutable variants and supported serialization formats, must expose valid JavaScript `Date` values when results are decoded. Query operands for those fields accept valid `Date` values through the public binding.

Encoding uses both cast metadata and storage metadata. A native timestamp, a string column with a datetime cast, and a date-only column do not necessarily share a wire representation. Custom serialization formatting is not automatically the replicated database representation.

Date-only values require a documented calendar convention. The proposed default is UTC midnight for the represented calendar date, with date-only input encoded from its UTC calendar components. This avoids implicit browser-local timezone changes, but remains a semantic choice to confirm before implementation. Timestamp-without-timezone columns likewise require an explicit timezone interpretation consistent with replication. Do not infer one from the user's browser.

JavaScript `Date` has millisecond precision and a bounded valid range. Reject invalid dates and out-of-range input. PostgreSQL timestamp precision beyond milliseconds cannot be represented exactly by `Date`; the integration must document that limitation, including its consequences for comparisons and cursors. Preserve raw transport cursor values internally instead of reconstructing them from formatted display values.

### 7.3 Enums and other casts

For backed enums, accept the generated enum values and expose the generated enum type. With Wayfinder `next`, import its runtime constants and types instead of generating a conflicting enum system:

```ts
import { PersonStatus } from '@/generated/App/Enums/PersonStatus';

const status = PersonStatus.Active;
```

The import path is illustrative and follows the application's Wayfinder output configuration. Backing values travel over the wire. An enum does not require a JavaScript instance, and arbitrary PHP enum methods do not automatically execute on the client.

Without the relevant Wayfinder output, the query generator still needs an equivalent scalar/enum contract. The exact integration mechanism is an implementation detail; missing enum generation must not quietly widen the field to an unconstrained string or number.

Reuse existing support for portable boolean, numeric, string, JSON, and other standard casts where semantics are known. Arbitrary custom PHP casts and computed Eloquent accessors are not automatically portable. They need an explicit supported codec or a generation diagnostic. A model accessor such as `name` must not appear in live result types unless its computation is actually supported.

## 8. Inertia response and React lifecycle

### 8.1 Absence from normal props

The integration removes Zero declarations before Inertia serializes prop values. A serializer returning `null` does not satisfy this requirement. Neither does a deferred prop that later fetches ordinary HTTP rows.

For the controller in section 2.1, the conceptual response is:

```json
{
    "component": "people/index",
    "props": {
        "title": "People",
        "errors": {}
    },
    "url": "/people",
    "version": "application-assets-version",
    "zero": {
        "bindings": {
            "people": {
                "query": "PersonController.index.people",
                "args": {}
            }
        }
    }
}
```

The `zero` envelope and query name above are illustrative integration metadata, not existing Inertia protocol fields. The implementation must choose a supported response extension mechanism and own its client handling. Metadata contains query identifiers and necessary normalized arguments, never result rows under `props.people`. Some entirely static bindings could be generated without per-response arguments, but that optimization must not complicate the main contract.

Metadata is client-visible and untrusted when returned to the server. It is not an authorization token. It must be serializable in initial HTML, subsequent visits, and history restoration, without storing live objects or callbacks.

### 8.2 Page injection

At application setup, install the Zero provider and Inertia integration once. It wraps resolved page components and handles each declared subscription automatically. It must preserve existing layouts, page component identity where appropriate, and normal Inertia setup behavior.

Generated wrappers use a stable React subscription structure. They cannot loop over a changing list of props while calling hooks conditionally. A stable generated wrapper or keyed subscription components can own the individual subscriptions.

The page sees ordinary Inertia props and injected live props together. A collision between an ordinary prop and a live binding is an error, rather than an implicit spread-order override.

Inertia's stored page object remains serializable. The live query object, methods, decoded dates, and subscriptions live outside it. A wrapped page-context API should expose the same effective props to descendants that the page receives. The import/name of that API remains open; raw upstream `usePage()` must not be typed as containing injected values unless the integration actually supplies them there.

### 8.3 Status and data

A representative public contract is:

```ts
type LiveQueryStatus = 'idle' | 'loading' | 'refreshing' | 'success' | 'failure' | 'stale';

interface LiveQuery<T> {
    readonly data: T;
    readonly status: LiveQueryStatus;
    readonly complete: boolean;
    readonly error: Error | undefined;
    retry(): void;
}

interface LiveInfiniteQuery<Row> extends LiveQuery<readonly Row[]> {
    readonly hasMore: boolean | undefined;
    readonly isLoadingMore: boolean;
    readonly exhausted: boolean;
    readonly capped: boolean;
    readonly stopReason: 'maximum' | 'exhausted' | undefined;
    loadMore(): void;
}
```

Reuse the existing package's equivalent lifecycle semantics and connection information rather than inventing an independent state machine. Final exported type and member names may align with those existing abstractions.

Collection `.data` is an array, including while there are no available rows. Single-record `.data` is a decoded record or `undefined`. The surrounding live prop object is present even while the subscription is idle or loading.

`complete` describes authoritative query synchronization; it must not be interpreted as a permanent guarantee of network connectivity or future authorization. Status distinguishes an empty synchronized collection from a query that has not loaded. `stale` represents retained data with an error; `failure` represents an error without usable data.

Applications must not treat incomplete single-record data as proof that a record is missing. A previously bound model may disappear after the initial request; once the live query completes without it, the page can render an unavailable state:

```tsx
export default function Person({ person }: Inertia.Pages.People.Show) {
    if (person.status === 'failure') {
        return <p role="alert">Unable to load this person.</p>;
    }

    if (!person.data && !person.complete) {
        return <p role="status">Loading person…</p>;
    }

    if (!person.data) {
        return <p>This person is unavailable.</p>;
    }

    return <h1>{person.data.first_name}</h1>;
}
```

### 8.4 Navigation, history, partial reloads, and SSR

| Event                           | Required behavior                                                                                                                                           |
| ------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Initial page load               | Resolve bindings, create the live prop objects, and subscribe without requiring HTTP result rows.                                                           |
| Ordinary visit                  | Select bindings from the incoming response and replace subscriptions when query identity or arguments change.                                               |
| Same component, new arguments   | Do not retain an old filter, record ID, or authorization identity by accident.                                                                              |
| Partial reload                  | Preserve bindings omitted by the partial response; replace explicitly updated bindings; provide an unambiguous mechanism to remove a binding.               |
| Back/forward                    | Restore serializable binding arguments and presentation state, then reacquire current live results. Do not replay stale row snapshots from Inertia history. |
| Instant visit                   | Use generated component helpers. Subscribe early only with known valid bindings; otherwise expose idle/loading live props until metadata arrives.           |
| Failed instant visit            | Dispose of speculative subscriptions and obey the actual server response, redirect, or error.                                                               |
| Unmount                         | Release subscriptions through native Zero lifecycle handling.                                                                                               |
| Authentication or tenant change | Clear identity-scoped results immediately and recreate subscriptions under the new context.                                                                 |
| SSR/hydration                   | Render a deterministic shell with stable live prop shapes; hydrate consistently before exposing browser cache results.                                      |

Zero results must not be merged through Inertia's normal row-merging or infinite-scroll protocol. `only: ['people']` can refresh the binding metadata, but does not request an HTTP page of people. A normal Inertia reload is not a substitute for Zero's query retry behavior.

Native Inertia's `<InfiniteScroll>` expects its own server pagination protocol. The Zero integration owns its loading controls; compatibility with that component must not be claimed without a specific adapter.

When using Wayfinder component helpers, follow the installed/generated API rather than hardcoding component strings or assuming the sketch's exact helper spelling. SSR of actual Zero rows is not required by this design; adding it would require a separate data and hydration contract.

## 9. Normal route model binding with live prop conversion

### 9.1 Preserve the Laravel request lifecycle

For this action:

```php
public function show(Person $person): Response
{
    return Inertia::render('people/show', [
        'person' => $person,
    ]);
}
```

Laravel performs its ordinary binding lookup and injects a real, hydrated `Person`. Normal middleware, policies, FormRequests, scoped bindings, custom binding resolution, and missing-model behavior continue to run as configured. Binding is not authorization; the application still declares its required authorization.

After the controller returns, the integration recognizes an eligible Zero-enabled bound model in the Inertia response and replaces that prop with a live single-record declaration before serialization. It does not send the hydrated model attributes to the browser as the prop's initial data.

The subsequent live query uses the model's actual primary key:

```php
Person::zeroQuery()
    ->where($person->getKeyName(), $person->getKey())
    ->one();
```

This is the conceptual equivalent, not an additional SQL lookup in the response conversion step. Compound keys require whatever explicit identity support exists in the supported model/schema system; do not assume a Laravel model has a scalar `id` column if it does not.

### 9.2 Route keys and binding constraints

If the route binds by a slug or custom route key, Laravel resolves that value first. The live query can then use the resolved model's canonical primary key; changing the slug later does not accidentally retarget the subscription.

Preserve the access and scoping constraints that make the bound record eligible. A parent-scoped route, tenant restriction, soft-delete rule, or custom binder can establish constraints beyond primary-key equality. Primary-key conversion identifies the record; it does not by itself reproduce those constraints for future live query invocations.

Represent relevant constraints in the registered server query or its authorization, including parent context when needed. If arbitrary custom binding logic cannot be represented for ongoing reads, require an explicit supported visibility/query declaration and report the gap. Never assume the initial successful lookup authorizes all future reads.

For example, after a record moves out of a parent or tenant, the subscription must no longer reveal it merely because its primary key is unchanged. Revocation behavior must follow the supported Zero authorization/context model, with identity changes causing immediate local cleanup. Static PHP checks do not magically become continuously reactive permissions; dynamic access changes need an explicit revalidation or reactive visibility strategy and tests.

### 9.3 Conversion scope and missing records

Automatically convert eligible route-bound models that are returned directly as page props and are configured for Zero. Preserve ordinary Inertia serialization for models outside the integration's eligibility rules. Do not indiscriminately turn every Eloquent object, collection, resource, or nested array into a subscription.

Loaded Eloquent relations, ad hoc attributes, and computed accessors on the bound instance are not automatically live query includes. Use an explicit query with `related()` when the page needs related live data. A future explicit snapshot escape hatch can retain ordinary Inertia data when needed; its public spelling is not specified here.

If binding cannot find the model during the HTTP request, Laravel's usual 404 or configured missing callback applies. If the record is deleted or becomes inaccessible later, the existing page receives the live query's completed no-result or authorization-error state. The original successful HTTP response is not retroactively turned into a 404.

There is no no-lookup binding mode, fake model proxy, alternate binder, or generated shortcut handler in the agreed scope.

## 10. Infinite loading and pagination

### 10.1 Infinite loading is a live growing window

Reuse the existing package's growing-window approach. With a page size of 25, the visible window grows from 25 to 50 to 75 rows. Each window remains a live ordered query; results are not permanently appended HTTP snapshots.

Request an additional row where the configured maximum allows it to determine whether another row exists. Do not infer exhaustion from an incomplete local cache result.

| Condition                                      | `hasMore`   | Meaning                                                                  |
| ---------------------------------------------- | ----------- | ------------------------------------------------------------------------ |
| Idle, incomplete, or query error               | `undefined` | There is insufficient authoritative information to advance.              |
| Complete, with a row beyond the visible window | `true`      | The next growth step is available.                                       |
| Complete, proven exhausted                     | `false`     | No further row currently exists.                                         |
| Maximum permitted window reached               | `false`     | The window cannot grow; this is not proof that the dataset is exhausted. |

Expose `capped`, `exhausted`, and `stopReason` so a maximum is distinguishable from the end of the dataset. Repeated load calls while growth is pending must not create duplicate or unbounded advances. A late callback from a previous query scope must not grow the current scope's window.

Changes to filters, route IDs, model contracts, or authorization identity reset the window. A sort-only change within the same authorized scope may retain the previous live ordering until its replacement synchronizes, matching existing lifecycle behavior. Results from a previous tenant or filter scope must never be retained as a loading convenience.

### 10.2 Deterministic ordering

Pagination requires a total, deterministic order. Preserve user ordering and add the complete unique key as a tie-breaker where it is not already sufficient. If no ordering is supplied to a pagination helper, use a documented key order.

Do this in the pagination layer. A plain native-compatible query must not acquire an undocumented ordering or limit just because the integration supports pagination.

Live inserts, updates, and deletes can move rows in or out of the window. This is expected live-query behavior, not a stable historical snapshot. Related query ordering and limits remain independently live.

### 10.3 Basic pagination

Provide a page-window helper alongside infinite loading. The recommended base contract is cursor navigation built on native `start()` and `limit()` semantics:

```php
public function index(): Response
{
    return Inertia::render('people/index', [
        'people' => Person::zeroQuery()
            ->orderBy('last_name', 'asc')
            ->orderBy('id', 'asc')
            ->paginate(25),
    ]);
}
```

```tsx
<button type="button" onClick={people.nextPage} disabled={people.hasNextPage !== true || people.isChangingPage}>
    Next page
</button>
```

`paginate()`, the frontend member names, previous-page behavior, cursor persistence, and URL synchronization are proposed API details to finalize before implementing this helper. Do not present a numbered Laravel `LengthAwarePaginator` contract as settled. Exact total counts and arbitrary page-number jumps are not implied by native ZQL or by having a cursor.

Page-window navigation must define behavior under concurrent inserts/deletes and reset on scope changes. Cursors must carry every required order value, preserve raw representation, and be validated on the server. They cannot introduce fields or ordering not allowed by the registered query.

## 11. Authorization and trust boundaries

The browser invokes a known query name with arguments. It does not submit a trusted arbitrary AST or select an unrestricted table, relationship, or column outside the generated query's contract.

For each server invocation, resolve the registered query, validate arguments, construct trusted current context, apply visibility and authorization, then compile the query. Invalid names, invalid arguments, and forbidden invocations receive structured errors that the live prop can expose.

Normal page middleware and model binding still protect HTTP navigation. The Zero endpoint must independently enforce equivalent required access for direct query calls, subsequent invocations, and reconnections.

Authorization must cover related rows and existence subqueries as well as the root result. A related query cannot leak an unauthorized row or reveal its existence merely because the parent is authorized. Schema exposure rules must also prevent replication of sensitive columns that live row decoding would otherwise reveal.

Public filters and cursors narrow or navigate within an authorized query; they cannot override a tenant constraint, expand visibility, or choose a different server identity. Resource limits and request-size limits remain explicit server safeguards. Pagination limits must be documented; silently truncating an ordinary query would violate its semantics.

## 12. PHP IntelliSense and static analysis

Generate useful editor metadata from the same model and query contracts. No separate IDE plugin is required for the first level of support.

The baseline is fluent-method completion, model-specific column names, relationship names, callback parameter types, enum suggestions where inferable, and result cardinality information. PHPDoc generics, generated stubs/helpers, and editor metadata can carry this information.

Laravel Idea supports package-provided `ide.json` completions for custom methods, including prepared string sets and Eloquent field completion. A generated model-specific builder identity can select the correct field/relationship set. Prefer those supported mechanisms over pretending the Zero builder is an Eloquent builder with unrelated methods and execution behavior.

Type-checking the value argument based on the exact preceding field string and operator is a deeper requirement. Prototype the supported editor and static-analysis behavior before promising TypeScript-equivalent inference. Editor support is an enhancement; runtime validation and codegen diagnostics cannot depend on an IDE being installed.

Generated editor files must not introduce duplicate runtime classes or affect production autoloading. Their regeneration must track changes to models, casts, relationships, and supported query APIs.

## 13. Delivery and compatibility with the existing package

Keep the query engine independent of Inertia. Controller discovery and live prop injection form an adapter over generated query contracts, native Zero queries, codecs, and lifecycle handling. Existing non-Inertia consumers should continue to use generated queries and explicit hooks where appropriate.

Reuse existing model metadata, public/wire conversions, authorized compilation, live result handling, and growing-window loading wherever they satisfy this specification. Extend or consolidate the owning layer when they do not. The existing restricted query compiler is not a substitute for full ZQL coverage.

Codegen produces coordinated PHP and TypeScript artifacts from the same application source. Watch relevant controller dependencies, routes, FormRequests, model metadata, and enums during development. A change in a helper used by a supported declaration must invalidate that declaration's generated output.

Production generation must use the current route collection, not a stale cached route table. Generated artifacts must be deployable together. Handle incompatible client/server contracts through explicit version compatibility and a reload/update path, rather than attempting to execute a stale query with a new schema silently.

A staged implementation may land the engine, analysis, adapter, and advanced features separately. Do not label the integration fully ZQL-compatible until the complete supported-version inventory and parity checks pass. Do not modify this sketch's controllers or install new dependencies merely to record this design.

## 14. Acceptance criteria

These are implementation requirements, not claims that tests have already been written or passed.

| Area                | Observable proof                                                                                                                                                           |
| ------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Basic declaration   | A controller query becomes a native live result without page-local subscription code.                                                                                      |
| Payload omission    | Initial HTML and subsequent Inertia JSON have no live result key in ordinary props; ordinary props remain intact.                                                          |
| ZQL parity          | Equivalent native and PHP queries preserve operators, grouping, relationships, options, ordering, bounds, and result cardinality across the complete supported API.        |
| Semantic parity     | High-risk cases also compare results against real supported storage/Zero execution; normalization snapshots alone are insufficient.                                        |
| PHP branches        | Optional inputs, null, empty strings, `"0"`, false, concatenation, and supported conditionals produce the intended query on both sides.                                    |
| Analysis failures   | Unsupported dependencies identify the source location and never silently broaden the query.                                                                                |
| Schemas             | Generated types and runtime validators agree on missing values, nullability, enum membership, normalization, and public versus wire types.                                 |
| Driver boundary     | Validator-specific syntax stays in generation output; query and encoding semantics do not depend on Zod internals.                                                         |
| Date inputs         | Valid dates are encoded before native request creation; invalid dates are rejected; equivalent arguments have stable query identity.                                       |
| Decoded results     | Date and enum casts apply to roots and included relationships without mutating native stored rows or inventing unsupported accessor fields.                                |
| Normal binding      | A real model reaches policies/controller code; initial missing-model behavior is preserved; the returned prop becomes live without serializing the bound row.              |
| Binding constraints | Custom route keys, parent scopes, tenant restrictions, and configured soft-delete behavior cannot be bypassed by direct or later live queries.                             |
| Authorization       | Direct calls, reconnections, root queries, related rows, and existence filters respect trusted current context.                                                            |
| Shared component    | Two routes with the same prop contract can share a component while receiving the correct query arguments for each visit.                                                   |
| Lifecycle           | Argument changes, unmounts, partial reloads, history navigation, instant visits, and authentication changes neither leak subscriptions nor expose another identity's data. |
| React behavior      | Strict Mode and changing page bindings do not violate hook ordering or duplicate effective subscriptions.                                                                  |
| Infinite loading    | Incomplete results do not falsely indicate exhaustion; repeated loading is bounded; caps differ from exhaustion; scope changes reset the window.                           |
| Pagination          | Stable ordering, cursor validation, and concurrent data changes follow the helper's documented contract.                                                                   |
| SSR                 | The deterministic shell hydrates without relying on HTTP row snapshots or persisting live objects in Inertia history.                                                      |
| Generation drift    | Application-source changes invalidate the relevant generated artifacts; incompatible deployments produce a recoverable update path.                                        |

Tests should exercise public behavior and distinct failure modes. Use native ZQL as the compatibility oracle and feature tests for the HTTP/security boundaries owned by the integration. Do not duplicate all Laravel or Inertia framework tests, or add a second suite that merely repeats the implementation's declarations.

## 15. Remaining implementation decisions

The architecture above is agreed. These details still need to be resolved explicitly while implementing it:

- Final builder/helper class names, generated paths, and coordination with Wayfinder's type emitter.
- The exact supported Zero version and complete public ZQL inventory for that version.
- Verified Ranger/Surveyor extension hooks and the precise initial supported PHP expression set.
- The response metadata extension mechanism, partial-update/removal representation, and contract-version signaling.
- The wrapped page-context import/API and which arguments are available before an instant visit's response arrives.
- Confirmation of date-only UTC semantics and explicit timezone behavior for timestamp-without-timezone storage.
- The explicit contract needed for custom binding/authorization logic that cannot be automatically represented in ongoing reads.
- The pagination helper's cursor, previous-page, history, and URL behavior. Numbered pagination with exact totals is a separate capability.
- The supported editor baseline after testing Laravel Idea metadata and generated PHP stubs.

These are implementation refinements, not reasons to restore no-lookup binding, add heterogeneous shared-page machinery, weaken authorization, or reduce the full ZQL compatibility requirement.

## 16. Reference material

The following upstream sources informed this design. Branch APIs are evolving; implementation must confirm its chosen versions rather than assuming the current branch remains unchanged.

- [Wayfinder `next`: package dependencies](https://github.com/laravel/wayfinder/blob/next/composer.json)
- [Wayfinder `next`: generation command and Ranger integration](https://github.com/laravel/wayfinder/blob/next/src/Console/GenerateCommand.php)
- [Wayfinder `next`: Inertia prop conversion](https://github.com/laravel/wayfinder/blob/next/src/Converters/InertiaData.php)
- [Wayfinder `next`: route and component helpers](https://github.com/laravel/wayfinder/blob/next/src/Langs/TypeScript/Converters/RouteMethod.php)
- [Wayfinder `next`: generated enum types and constants](https://github.com/laravel/wayfinder/blob/next/src/Converters/Enums.php)
- [Laravel Ranger: application discovery](https://github.com/laravel/ranger)
- [Laravel Ranger: Inertia response analysis](https://github.com/laravel/ranger/blob/main/src/Collectors/Response.php)
- [Laravel Surveyor: analysis capabilities and limitations](https://github.com/laravel/surveyor)
- [Laravel 13: route model binding](https://github.com/laravel/docs/blob/13.x/routing.md#route-model-binding)
- [Inertia v3: responses](https://inertiajs.com/docs/v3/the-basics/responses)
- [Inertia v3: instant visits](https://inertiajs.com/docs/v3/the-basics/instant-visits)
- [Inertia v3: protocol](https://inertiajs.com/docs/v3/core-concepts/the-protocol)
- [Zod: bidirectional codecs](https://zod.dev/codecs)
- [Laravel Idea: custom completions](https://laravel-idea.com/docs/ide_json/completion)
- [Laravel Idea: helper code integration](https://laravel-idea.com/docs/ide_json/helper_code)

The installed Zero query interfaces and query-registry implementation in the existing `zero-for-laravel` checkout were also inspected for the query surface, Standard Schema synchronization requirement, and retention of original named-query arguments. The existing package's live-query hook and conversion helpers informed the reuse requirements. None of those observations replace verification against the version selected for implementation.
