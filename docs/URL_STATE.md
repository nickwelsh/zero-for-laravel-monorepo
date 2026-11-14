# URL state integration

URL state is opt-in. Zero queries and the framework-neutral table descriptor do
not import a browser or Inertia. A page can own several independent instances of
the same descriptor, alongside other queries, without creating separate pages.
See `examples/todos/resources/js/pages/url-state.tsx` and its `URLStateController`
for a working two-source page at `/url-state`.

## Wire contract

Each table uses one query parameter, conventionally `table.<instance>`, whose
decoded value is JSON: `{"version":2,"state":{...}}`. Use `URLSearchParams` to
percent-encode it; do not concatenate JSON into a URL. Instance keys must be
unique on a page, even when descriptors are identical. Writers preserve other
parameters (including repeated ones), the path, and the fragment.

The supported state is the existing `TableState`: `filter`, ordered `sort`,
ordered `groupBy` rules, and `expanded`. For example:

```json
{
    "version": 2,
    "state": {
        "groupBy": [
            { "field": "completed", "direction": "desc" },
            { "field": "list", "direction": "asc" }
        ]
    }
}
```

Each rule requires a capability alias and `asc` or `desc` direction. Rule order
defines nesting; direction orders each directory's keys, with NULLS LAST in both
directions. Relation directories order foreign keys, not display labels. Row
`sort` remains independent. No additional query AST or security scope is
accepted. PHP `TableURLState` and TypeScript `readTableState`, `writeTableState`,
and `normalizeTableState` reuse the table's capability validators. Generated
descriptors already expose the capability-specific `TableState`/`TableQueryInput`
types; there is no separately handwritten per-table URL schema to drift.

Precedence is shallow, by field:

1. Descriptor defaults: null filter, declared sorting/grouping, empty expansions.
2. Application table defaults, then saved-view defaults, merged by the caller.
3. Fields explicitly present in the URL.

Missing fields inherit. `filter: null` and empty arrays explicitly clear fields.
Arrays and filter trees replace, rather than concatenate. Writers emit all four
fields, so a shared full-state link freezes the current view. Partial links can
inherit future default changes. `reset(namespace)` removes that namespace and
restores its configured defaults, not the state from the initial URL. Keep saved
defaults separate from server-normalized initial inputs for this reason.

Invalid URL input restores the whole namespace's defaults, not a partially
accepted subset. Invalid application defaults and invalid writes throw, so
programming errors are not silently hidden. Unknown envelope/state/filter/sort
keys, capabilities, operators, and versions are rejected. Duplicate parameters
and bracket-array variants are invalid. Invalid links are not rewritten on load;
reset or the next valid write repairs the namespace.

Version-1 envelopes and version-2 links containing legacy `groupBy: ['completed']`
are invalid and fall back to the whole namespace's application/descriptor defaults.
There is no silent string-array conversion. Migrate application defaults and
saved views to explicit rules, regenerate with `php artisan zero:generate`, and
deploy paired PHP/TypeScript contracts. Capabilities and metadata requests and
responses are also version 2; contract fingerprints include the protocol version.
Allowlists, maximum depth, authorization, scopes, nested filters and multi-sort
behavior are unchanged.

Limits (per namespace): 16,384 decoded UTF-8 bytes; filter depth 8, root counted
as 1; 100 total filter nodes; 1–100 membership operands; 1,000 Unicode characters
per string operand; 100 expanded IDs of at most 2,048 UTF-8 bytes each. Boolean
groups must be nonempty. Types, enum choices, explicit null operations, ordered
unique sorts, grouping uniqueness/depth, and date offsets are capability checked.
Expanded IDs remain opaque table-generated strings: stale IDs are inert, not
server query paths or permissions. Deployment/proxy URL limits can be lower;
large saved views should use application-owned saved-view IDs instead of URLs.

`setGroupBy` clears expansion on any actual rule change, including reordering or
changing only a direction; identical rules preserve expansion. Full controlled
state and URL restoration can explicitly supply expanded IDs alongside the rules
to restore a complete view. Collapsing a parent preserves its descendants' saved
expansion while rules are unchanged. Metadata cursors are scoped to all grouping
rules, including order/direction. A rule change clears metadata, cancels obsolete
requests and ignores stale responses. Snapshot directory pages use keyset cursors;
concurrent data changes between pages may require a refresh. Row pagination still
uses live prefixes and never treats a snapshot count as proof of exhaustion.

## PHP first, before subscriptions

Build the same table definition used by the generated descriptor, with the actor
resolved by Laravel. Decode **the raw query string**, not `$request->query()`:
PHP otherwise changes dots to underscores and collapses duplicate keys.

```php
$table = $definition->definition(new TableContext($request));
$defaults = [...$applicationDefaults, ...$savedViewDefaults];
$initial = TableURLState::read(
    $table,
    $request->server('QUERY_STRING', ''),
    'table.people',
    $defaults,
);
return Inertia::render('constituents', [
    'initialInputs' => ['people' => $initial],
    'initialURL' => $request->getRequestUri(),
    'defaults' => ['people' => $defaults],
]);
```

Repeat for each source on that page. Supply these normalized inputs on the first
render, before mounting table/query subscriptions. `TableURLState::encode` emits
the same versioned envelope for server-authored links; percent-encode it when
building a query string. The codec does not run a row query or authorize a saved
view. Applications must authorize saved-view access independently.
PHP and TypeScript apply the same version-2 rule validation, fallback precedence
and explicit expansion restoration on direct loads, SSR and Back/Forward. The
packaged `@nickwelsh/zero-table/inertia` hook with `urlState: true` uses that same
contract for single-owner pages; the coordinator below supports multiple sources.

Mandatory authorization, tenant predicates, and row visibility stay in the
table's base query/`visibleTo` and the authoritative Zero transport. Never put
them in defaults or URL filters: users can clear or replace every URL field.
The example's active/completed defaults are intentionally editable view inputs.

## Page ownership and interaction

Import `createPageURLState` and `usePageURLState` from
`@nickwelsh/zero-for-laravel/react`, `tableStateCodec` from
`@nickwelsh/zero-table/react`, and `inertiaURLAdapter` from
`@nickwelsh/zero-for-laravel/inertia`. Create one coordinator per page mount:

```tsx
const [page] = useState(() =>
    createPageURLState(
        inertiaURLAdapter,
        {
            people: tableStateCodec(people.capabilities, {
                key: 'table.people',
                initialState: defaults.people,
            }),
            companies: tableStateCodec(companies.capabilities, {
                key: 'table.companies',
                initialState: defaults.companies,
            }),
        },
        { url: initialURL, state: initialInputs },
    ),
);
const state = usePageURLState(page);
const peopleTable = useDataTable(people, {
    state: state.people,
    onStateChange: (next) => page.set('people', next),
});
```

Use `useDataTable` from the React entry point in controlled mode; do not enable
its separate `urlState` owner. For SSR, supply an adapter whose `getURL()` returns
the Inertia page URL, rather than accessing `window` (as in the example). The
third argument pairs the authoritative PHP snapshot with its original request
URL. Inertia may remount with old props during history restoration; the snapshot
is only used when that URL's path/query matches, otherwise the restored URL is
decoded synchronously. Defaults on the codecs remain the reset baseline.
Remount the coordinator when the page/saved-view identity or
default policy changes. Do not create it on every render or share it across SSR
requests. Hook subscription cleanup cancels pending timers on unmount.

`page.set(key, valueOrUpdater, {history, debounceMs})` validates first, publishes
controlled state immediately (therefore updating Zero subscriptions), and writes
the URL immediately or after the debounce interval. Functional updaters compose
against the latest state, not a captured render. Debounce delays URL/history,
not the controlled input or Zero subscription; if subscriptions must also wait,
keep a local search draft and debounce committing it to `page.set` instead.

Default history is `replace`; use `push` for deliberate navigable changes such as
sorts or saved-view selection. Debounced search usually uses `replace`, so typing
does not add one entry per character. `push` with debounce adds one entry after
the last edit. Back/Forward restores all namespaces and cancels pending writes
on URL restoration, preventing an old search from overwriting restored state.
A pending write merges with the latest page URL, and another namespace's write
does not discard the local debounced value. Reset cancels that namespace's timer.

Custom `URLStateAdapter` subscriptions report `'write'` for ordinary URL edits
and `'restore'` for history or page navigation. Writes preserve pending drafts;
restoration cancels them, even when the URL is unchanged. Notifications without
an argument retain restoration semantics for existing adapters. Inertia tracks
each pending visit until its own completion, so repeated URLs do not acknowledge
later visits prematurely.

The optional Inertia adapter uses client-side `router.push`/`router.replace`,
preserving page props, component state, scroll, and Inertia history. No server
visit is made per edit. Scalar `createInertiaAdapter({url: ...})` bindings and
Zero Table use the same pending-URL adapter, preventing cross-source lost writes.
Do not assign two owners to the same parameter. Use the Inertia adapter on an
Inertia page; native History API writes bypass Inertia's page/props history.
The existing scalar mapping remains scalar-only; nested table state belongs to
the page coordinator, not that mapping or speculative navigation route inputs.

Other validated query inputs can participate by implementing `URLStateCodec<T>`
(`read`, `write`, `reset`). Their PHP decoding and schema remain application-owned;
this library does not invent a generic validator for arbitrary query shapes.

## Presentation ownership and remaining gaps

Column visibility, column order, and user-resized widths are **not implemented
TableState features**. Declared columns and widths belong to the rendering
configuration; this integration does not claim to add hide/reorder/resize UI.
An open record panel is page state, not a table filter or expanded group.

Applications can give such presentation state its own versioned codec and
namespace (for example `view.constituents`) in the same coordinator. Validate
column IDs against the application's declared columns, enforce unique order and
finite bounded widths, bound record IDs, and mirror that validation in PHP for
direct loads. Reconcile unknown IDs after schema changes. Resolve an open record
through an independently authorized query; a panel ID is never proof of access.
Presentation state must not be passed to generated table row/metadata arguments.
No built-in presentation codec, panel renderer, saved-view persistence, cross-tab
synchronization, or automatic nested query route-prefetch resolution is supplied.

The two-source example, PHP/TypeScript interop tests, and controlled hook tests
exercise full state round trips, direct initial inputs, malformed/oversized and
complex input, namespace isolation, pending writes, reset/default precedence,
debounce, and Back/Forward. No release is required to use this implementation
from the workspace.
