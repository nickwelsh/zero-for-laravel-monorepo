# Editable Zero tables: API contract and implementation plan

Status: accepted API contract; implementation proceeds in six stacked milestones.

This document records the accepted contract and release gates, not a guarantee
that every gate has passed. The public package now replaces the `examples/todos`
grouping spike; its consumer instructions live in `packages/table/README.md`.
Implementation and stacked pull requests are authorized separately in the
originating thread; this document does not authorize publication, merge, or deployment.

## 1. Outcome and boundaries

Build an Airtable-like table foundation: developer-declared query capabilities,
arbitrary independent group expansion, infinite live rows, snapshot group counts,
window/panel virtualization, and configurable inline editors. Supply both React
hooks and unstyled components; consumers own appearance and their UI library.

| Location                                               | Owns                                                                                                                               | Does not own                                                              |
| ------------------------------------------------------ | ---------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------- |
| Existing `nickwelsh/zero-for-laravel` Composer package | PHP table definitions, input validation, shared predicates, permissions, native query registration/generation, SQL group metadata  | Table rendering or a new mutation transport                               |
| New `@nickwelsh/zero-table` npm package, root entry    | Column/editor definitions, state and descriptor types, pure helpers                                                                | React, subscriptions, DOM, or a new framework-independent reactive engine |
| `@nickwelsh/zero-table/react`                          | Session state, query owners using the existing live-data adapter, viewport demand, editing sessions, hooks and unstyled components | An independent copied-row cache or client-derived global grouping/counts  |
| `@nickwelsh/zero-table/formbraid`                      | Optional mapping of generated Formbraid descriptors, validation, submission outcomes and editor metadata                           | Replacement field-option infrastructure                                   |

The new npm package depends on the existing client package. The existing client
must not import the table package. React, TanStack Virtual, and Formbraid are
isolated to the entry points that need them. Importing the root entry must not
load React, TanStack Virtual, or Formbraid. One npm release includes all subpaths;
there is no new Composer package initially.

Reuse native Zero query execution, `useData`'s authorization behavior, model casts,
registered controller mutations, and the existing `zero:generate` pipeline. Table
support is optional: applications with no registered tables retain their existing
generated output and dependencies.

## 2. PHP declaration and generated descriptor

Proposed application definition; imports are omitted for brevity. `Table`,
`TableContext`, and `Allowed*` live under `ZeroForLaravel\Tables` initially.

```php
final class TodosTable
{
    public function definition(TableContext $context): Table
    {
        return Table::for(Todo::class)
            ->visibleTo($context->user())
            ->allowedFilters([
                AllowedFilter::exact('completed'),
                AllowedFilter::exact('list', 'list_id')->nullable(),
                AllowedFilter::text('title')
                    ->operators(['equals', 'contains', 'startsWith']),
            ])
            ->allowedSorts(['created_at', 'title'])
            ->allowedGroups([
                AllowedGroup::field('completed'),
                AllowedGroup::belongsTo('list')->label('name'),
            ])
            ->defaultSort('created_at')
            ->defaultGroupBy(['completed', 'list'])
            ->maxGroupDepth(2)
            ->pageSize(50);
    }
}
```

Register explicitly in `config/zero.php`:

```php
'tables' => [
    'todos' => App\Tables\TodosTable::class,
],
```

`TableContext` exposes the server-resolved actor and declared application context;
it is not constructed from client identity arguments. Generation treats it as a
symbolic input, never samples a user or executes application database lookups.
Protected models require portable visibility. Table definitions cannot weaken
publication, field-exposure, tenant, or permission rules.

The declaration builder is mutable, like `ZeroQuery`, and constructed afresh for
each definition evaluation; never cache an actor-bound builder across requests.
`where(field, operator, value)` and grouped `where` conditions can declare
mandatory portable business constraints. Global scopes, including soft-delete
and tenant behavior, must lower identically on both paths; an SQL-only global
scope cannot be silently applied to counts while omitted from live rows.

| Declaration                                                     | Public meaning                                                           |
| --------------------------------------------------------------- | ------------------------------------------------------------------------ |
| `AllowedFilter::exact(alias, field = alias)`                    | Scalar `equals` / `in` capabilities derived from the model field         |
| `AllowedFilter::text(alias, field = alias)->operators([...])`   | A subset of `equals`, `notEquals`, `contains`, `startsWith`, `endsWith`  |
| `AllowedFilter::partial(alias, field = alias)`                  | Case-sensitive literal `contains` shorthand                              |
| `AllowedFilter::number(alias, field = alias)->operators([...])` | A subset of `equals`, `notEquals`, `in`, `gt`, `gte`, `lt`, `lte`        |
| `AllowedFilter::date(alias, field = alias)->operators([...])`   | Supported encoded-instant comparisons from the numeric comparison family |
| `filter->nullable()`                                            | Add explicit `isNull` / `isNotNull` operations for a nullable field      |
| `allowedSorts([...])`                                           | Current-table scalar field names; no arbitrary expressions               |
| `AllowedGroup::field(field)`                                    | Group by an admitted scalar field; date/JSON fields excluded initially   |
| `AllowedGroup::belongsTo(relation)->label(field)`               | Group by the declared foreign key, resolve authorized display labels     |
| `defaultSort(field)` / `defaultGroupBy(fields)`                 | Defaults validated against the same capabilities as user input           |
| `pageSize(n)` / `maxGroupDepth(n)`                              | Server-owned row increment and maximum permitted nesting                 |

Absent overrides, defaults are 50 rows per leaf, no user filter, no grouping,
empty expansion, implicit primary-key ascending ordering, and read-only editing.
The default maximum group depth is the smaller of 3 and the number of allowed
dimensions. Filter/sort/group allowlists start empty. A bare text/number/date
filter exposes only `equals` until its operators are declared.

The first release accepts declarative operations with supported lowering to both
execution targets. Arbitrary request-time PHP, Eloquent callbacks, raw SQL and
ordinary Zero-only scopes are not automatically portable. Unsupported declarations
fail generation with the table, field and source location. Custom filters/scopes
can be added later through checked shared predicates; they are not a v1 escape
hatch that makes counts approximate without saying so.

`zero:generate` produces:

- A typed default export at `resources/js/zero/tables/todos.ts`, imported as
  `@/zero/tables/todos` in the example.
- Native live query definitions and authoritative server registrations using the
  existing registration/contract-freshness mechanism.
- The metadata route binding and a contract fingerprint shared by server and
  client. The descriptor supplies the URL; applications do not hard-code it.
- Capabilities: public aliases, underlying public field associations, operator and
  value schemas, allowed sorts/groups, defaults, page size and group-depth limit.
- Existing model decoding/casts and stable primary-key identity. Schema/publication
  exposure remains authoritative; hidden columns are not a security boundary.

The generated descriptor contains no actor credential, API key, raw SQL, private
policy implementation, or replicated row data. A descriptor/registration mismatch
fails closed with a reload-required error rather than partially applying a query.
Generated files participate in `--check`, ownership manifests and stale-file cleanup.

## 3. Query state and semantics

The runtime uses these conceptual shapes. Generated types narrow strings and
values to each table's actual capabilities; consumers do not pass arbitrary field
names through a broad `string` API.

```ts
type FilterNode =
    | { kind: 'and' | 'or'; children: readonly FilterNode[] }
    | { kind: 'condition'; field: string; operator: string; value?: unknown };

type GroupPath = readonly {
    field: string;
    value: string | number | boolean | null;
}[];

type TableState = {
    filter: FilterNode | null;
    sort: readonly { field: string; direction: 'asc' | 'desc' }[];
    groupBy: readonly string[];
    expanded: readonly string[]; // Opaque group IDs, not labels or array indices.
};
```

There is one normalized predicate representation for the live and SQL paths.
Extend the existing checked condition machinery where practical; do not build a
general browser-ZQL-to-Eloquent translator or maintain two independent parsers.
Mandatory conditions wrap the complete user tree:

```text
visibility AND tenant/base constraints AND user filter AND ancestor group path
```

### Filters

- A null filter means unrestricted user filtering, not unrestricted visibility.
  AND/OR nodes require at least one child. Arbitrary NOT trees are deferred;
  explicit negative comparison operators have SQL null semantics.
- `exact()` allows `equals` and `in` for the declared scalar type. `text()` allows
  explicitly selected text operators; `partial()` is shorthand for contains only.
  Numeric/date comparison support uses explicitly declared operators, not coercion
  of arbitrary text into a query.
- `nullable()` additionally permits `isNull` and `isNotNull`, which have no value.
  Null equality and null members of `in` are rejected; express them with explicit
  null operators and OR. Empty membership lists and empty text-search values are
  rejected, not silently ignored.
- Text matching is literal and case-sensitive initially. Escape `%`, `_` and
  backslash consistently for pattern operators. No regex, wildcard language,
  locale-aware matching, or implied case folding. Unicode/collation parity is a
  release gate; unsupported behavior is rejected or narrowed before shipping.
- Dates use the model's generated encoding. Wire inputs require an explicit
  offset and normalize to UTC; display timezone is not a filter timezone. Do not
  admit precision the native representation cannot preserve. Date grouping and
  calendar buckets are deferred.
- Arrays remain arrays; IDs, booleans and numbers are not comma-split strings.
  Enums use their declared values. Public aliases resolve only through registration.

Initial server limits are filter depth 8, 100 conditions, 100 values per membership
condition and 1,000 characters per text operand, additionally constrained by field
contracts. These are validation boundaries, not limits on expanded groups.
Client and server share the limits; applications may lower them. Any increase
requires the same complexity and parity checks.

### Sorting and grouping

- Sort only allowed current-table scalar columns. Preserve requested sort order
  and append any missing primary-key fields ascending, matching native Zero.
  No local reordering of fetched pages to simulate a global sort.
- Native null ordering applies: first ascending, last descending. Related labels,
  counts, formulas and arbitrary frontend accessors are not sortable in v1.
- Group by allowed scalar fields or belongs-to foreign-key identity. A belongs-to
  declaration resolves public alias `list` to `list_id`; labels come from separately
  authorized related rows. An unavailable label does not merge IDs or expose a
  private label. Null is an explicit unassigned bucket.
- Group fields cannot repeat; nesting must respect `maxGroupDepth()`. V1 supports
  configured depths up to 3. Group identity encodes the entire typed path; null,
  empty string, the number 1 and the string `"1"` cannot collide.
- Directories use deterministic group-key ordering, not label/count ordering.
  SQL keyset comparisons must use the same scalar/null/string ordering as their
  corresponding directory cursor. No offset or count-based random scrolling.
- Ungrouped tables use the same row-loading path with an empty group path and no
  synthetic visible group header.

## 4. Transport: live rows and snapshot metadata

Rows remain ordinary named Zero queries through the existing authenticated
transform path. Their input is validated table state plus a complete leaf path
and the requested live window. The server resolves model/columns itself. Clients
cannot supply SQL, native ASTs, an alternate actor, or scope-removal instructions.

Each leaf starts at `pageSize()` and grows one prefix by that size. Request one
extra row to prove that more rows exist. Only current confirmed query results can
establish exhaustion; snapshot counts, partial emptiness and cached emptiness
cannot. Do not concatenate independently live cursor pages. Input window values
must satisfy the native positive-integer limit; reaching a limit reports a
distinct capped state, not exhaustion. A separate product data-retention policy
may later impose a lower limit, but cannot silently truncate rows.

### Metadata operation

Default generated endpoint: authenticated, CSRF-protected
`POST /zero/tables/{table}/metadata`, respecting the configured Zero route prefix.
Route bindings use the table's middleware/context contract. A table definition
does not make its counts public. This is a separate JSON operation, not an attempt
to return aggregates through Zero's custom-query protocol.

```ts
type MetadataInput = {
    version: 1;
    contract: string;
    filter: FilterNode | null;
    groupBy: readonly string[];
    directories: readonly { path: GroupPath; after: string | null }[];
};

type MetadataResponse = {
    version: 1;
    requestKey: string;
    observedAt: string;
    totalCount: number;
    directories: readonly {
        path: GroupPath;
        groups: readonly {
            id: string;
            value: string | number | boolean | null;
            label: string;
            count: number;
        }[];
        nextCursor: string | null;
    }[];
};
```

`totalCount` counts the authorized, filtered unbounded root set. Group counts count
that set further constrained by the complete ancestor/current path. They do not
include the live row limit or cursor. Parent directory paths must be strict
prefixes of the current grouping; an empty directories array requests only the
total. Group IDs encode the full resulting path, not just the returned value.

Batch up to 16 requested directories; return up to 50 child groups per directory
with an extra-key probe. Use grouped SQL, not a count query per returned child or
PHP hydration of all matching rows. Exists filters must not multiply root rows.
Labels load in authorized batches. All count/group/label reads in one response
use one read-only repeatable-read PostgreSQL snapshot on the supported connection.
This promises internal response consistency, not alignment with Zero. Another
HTTP page or refresh has a new snapshot; `observedAt` is not a replication watermark.

Cursors are opaque and bound to contract, actor/context, filter, grouping and
directory path. Tampering or reuse with another scope is rejected. They are not
authorization credentials. Concurrent inserts/deletes between directory pages can
change discovery; deduplicate group IDs, expose snapshot semantics and refresh to
rebuild the directory. There is no pinned cross-request database transaction.

The client matches responses to canonical request state and the current access
generation, aborts obsolete work, and rejects late responses even if cancellation
races. Responses are private/no-store. Failures are errors or stale data, never a
zero count. Authorization/session loss clears protected rows, labels, counts,
choices and drafts; transient failures can retain authorized stale metadata.

HTTP errors use the existing application envelope: 401/419 for session failure,
403 or the configured concealed 404 for denied access, 404 for an unknown table,
409 for a stale contract requiring reload, and 422 with field paths for invalid
state/cursor inputs. Do not expose a concealed table's capabilities in an error.
Native row errors retain the existing Zero query error protocol, normalized to
the same actionable session states by the React adapter.

Counts and discovery refresh explicitly through `refreshCounts()`. Accepted local
mutations coalesce a refresh and mark snapshots stale immediately; reconnect or
returning focus also schedules a refresh when authorized. There is no background
polling promise in v1. Remote changes remain live inside subscribed leaves, but a
new group may remain undiscovered until metadata refresh. Counts can disagree
temporarily with Zero because of replication lag or optimistic writes.

## 5. Frontend contract

```tsx
import { column, editors } from '@nickwelsh/zero-table';
import { useDataTable } from '@nickwelsh/zero-table/react';
import todos from '@/zero/tables/todos';

const columns = [
    column.text('title', { header: 'Title', editor: editors.text() }),
    column.boolean('completed', {
        header: 'Completed',
        editor: editors.checkbox(),
    }),
    column.relation('list_id', {
        header: 'List',
        query: { filter: 'list', group: 'list' },
        editor: editors.relation(listOptions),
    }),
];

const table = useDataTable(todos, {
    columns,
    initialState: {
        groupBy: ['completed', 'list'],
        sort: [{ field: 'created_at', direction: 'asc' }],
    },
    editing: {
        enabled: editMode,
        canEditRow,
        canEditColumn,
        canEditCell,
        submit: saveTodoCell,
    },
});
```

`listOptions` is the shared query-backed option source described in section 7.
`saveTodoCell` is application-owned or supplied by the optional mutation/Formbraid
adapter. Examples do not assume that a full-record FormRequest accepts cell patches.

Column IDs are unique and stable. Bound columns default to their source field ID;
optional `query` associations map aliases to filter/sort/group capabilities.
Definitions are type-checked against the generated descriptor when supplied to
the hook. Display-only columns may use custom accessors but have no implicit
backend capabilities or mutation field. Built-in editors render through the
React entry point; root definitions carry no React component dependency. Custom
React renderers receive the same typed column/cell state as built-in components.

`useDataTable` creates the session handle. Mount exactly one DOM-free
`<DataTable.Root table={table}>` while that session is active, even for a custom
hook-only grid. Root hosts the stable query-owner components; all rendering
components below it are optional. This makes dynamic leaf subscriptions possible
without dynamic hook calls, a competing query engine, or a styling wrapper.
Unmounting Root stops its owners; ordinary rerenders and virtual item unmounts do
not. Existing ZeroProvider/access boundaries still wrap the table.

### Hooks and session surface

| API                                                               | Contract                                                                                                                         |
| ----------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------- |
| `useDataTable(descriptor, options)`                               | One mounted table session; state, loaded items, query demand, snapshots and edit ownership                                       |
| `table.state`                                                     | Readonly current filter, sort, grouping and expansion state                                                                      |
| `table.setFilter(tree)` / `setSort(sort)` / `setGroupBy(fields)`  | Validate against descriptor and change state without an Inertia visit                                                            |
| `table.expand(groupId)` / `collapse(groupId)` / `toggle(groupId)` | Change only that group; descendants retain their expansion state                                                                 |
| `table.items`                                                     | Flattened currently discovered headers, loaded rows and loading/error/sentinel entries, in display order                         |
| `table.loadMore(itemId)` / `retry(itemId)`                        | Request the identified directory or leaf window; no global page number                                                           |
| `table.count`                                                     | Last authorized root snapshot value/time with pending, stale or error state; absent before success                               |
| `table.refreshCounts()`                                           | Refresh root and relevant loaded directory snapshots without discarding compatible row windows or expansion                      |
| `table.diagnostics`                                               | Active/warm leaf counts, retained requested windows, rendered/loaded rows and metadata work; no secrets or row dumps             |
| `useTableViewport(table, { scrollElement })`                      | Demand plus virtual items, total measured size and measurement callback; `scrollElement` is `'window'` or a scroll-container ref |
| `useTableCell(table, rowId, columnId)`                            | Current value, editability, draft, errors, submission state, warnings and begin/setDraft/submit/cancel actions                   |

Options allow either `initialState` with internal ownership or controlled `state`
plus `onStateChange(next)`, not both. `initialState` may be partial. No URL,
localStorage or cross-tab persistence is implied; applications can control state
to add those behaviors. Descriptors are shareable, mutable sessions are not.

Loaded render items have a discriminated union, not a union of model rows and
objects pretending to be model rows:

```ts
type CountState = {
    value: number | undefined;
    observedAt: string | undefined;
    pending: boolean;
    stale: boolean;
    error: Error | undefined;
};

type TableItem<Row> =
    | { kind: 'group'; id: string; path: GroupPath; depth: number; label: string; expanded: boolean; count: CountState }
    | { kind: 'row'; id: string; groupId: string | null; row: Row }
    | { kind: 'loading' | 'load-more' | 'empty'; id: string; groupId: string | null }
    | { kind: 'error'; id: string; groupId: string | null; error: Error };
```

IDs are stable opaque session keys, prefixed by item kind. Row identity encodes
the complete native primary key without string/number collisions and survives a
group move. Use original row key fields for mutation route arguments, not the
opaque render ID. Structural entries are keyed by directory/leaf identity; null
`groupId` denotes an ungrouped table or root directory. A count value/timestamp
remain undefined until a successful response; refresh/error flags never invent 0.

Filter/sort/group changes create the appropriate new query identity. Never display
rows from an incompatible identity as current results. Reusing a visited identity
may recover its authorized cached data and requested window. Grouping changes
invalidate incompatible paths; compatible group IDs retain expansion. A label
change never changes identity.

### Loading, retention and virtualization

- Query/window owners live outside disposable row/header DOM. Use stable
  component-per-resource owners and existing `useData`, not variable-length loops
  of hooks or raw native subscriptions bypassing access gates.
- All explicitly expanded leaves stay enabled, including offscreen leaves and
  leaves under a collapsed ancestor. There is no accordion rule or silent
  subscription cap. Closing a parent hides descendants, not their saved state.
- The default proactive budget is four additional non-expanded leaf windows
  across the session. Prioritize visible/overscan candidates and pointer/focus
  intent. Discover child directories before their leaves can be warmed; do not
  enumerate every possible branch of a collapsed tree.
- Recently deactivated queries use Zero's existing five-minute retention where
  authorization permits. Keep requested window/expansion metadata for the session,
  but do not copy canonical row arrays into a second cache. Choose TTL at initial
  registration; do not attempt to shorten it later with increase-only TTL updates.
- Cached/partial rows render without an initial spinner when the existing access
  contract permits. A refresh indicator is separate from initial loading. Cold
  cache, expired data and renewed protected access may require a loading state;
  the table never bypasses those gates to promise perpetual offline availability.
- Flatten only discovered groups and loaded rows plus sentinels; never allocate
  one placeholder per counted row. Window and element scrolling share row keys,
  variable-height measurement, overscan and lifecycle behavior.
- Pin focused/editing items as necessary, retain drafts outside row DOM, and
  preserve scroll anchors during measurement, live movement and prefix growth.
  Opening a distant leaf must not reset the shared viewport to zero.
- DOM virtualization does not bound loaded prefixes, Zero storage or retained
  server queries. Arbitrarily many live expanded leaves consume resources. Expose
  diagnostics and document that cost; memory-bounded random access is not a v1 claim.

### Unstyled components

```tsx
<DataTable.Root table={table}>
    <DataTable.Viewport scrollElement="window">
        {(item) => {
            if (item.kind === 'group') return <MyGroupHeader group={item} />;
            if (item.kind === 'row') return <MyRow row={item} />;
            return <MyLoadState item={item} />;
        }}
    </DataTable.Viewport>
</DataTable.Root>
```

`Root`, `Viewport`, `Group`, `Row`, and `Cell` use the same session/hooks; they do
not create a second state machine. Components accept classes/render overrides and
emit documented state attributes. `Viewport` supplies positioning/measurement
wrappers; `Group`, `Row`, and `Cell` supply interaction/accessibility props, not a
theme. Hook-only consumers receive equivalent prop getters and measurement hooks.
Renderers must handle group, row, loading, load-more, empty and error item kinds.

Use one consistent accessible grid interaction model: meaningful row/cell and
group expansion semantics, labels, focus restoration, and no tab stop for every
offscreen cell. Keyboard navigation does not assume snapshot counts reveal
unloaded positions. ShadCN remains an example integration, not a dependency.

## 6. Inline editing contract

Default is read-only. When enabled, effective editability is the conjunction of
table enabled, optional row/column/cell gates, and an available editor/submission
binding. Missing optional UI gates inherit allowed; false at any layer wins.
Changing a UI gate cannot grant server permission. Laravel authorizes the row and
submitted fields again on every mutation.

```ts
type CellChange<Row> = {
    row: Row;
    rowId: string;
    columnId: string;
    field: string;
    originalValue: unknown;
    value: unknown;
    patch: Readonly<Record<string, unknown>>;
};

type CellSubmitResult =
    | { status: 'accepted'; warning?: Error }
    | { status: 'invalid'; errors: Readonly<Record<string, readonly string[]>> }
    | { status: 'rejected'; error: Error }
    | { status: 'unconfirmed'; error: Error };
```

Generated/column types narrow the field, value and patch. `submit(change)` returns
`Promise<CellSubmitResult>`; a normal cell patch contains only its bound field.
Composite custom editors may declare their complete patch fields explicitly.
Every patched field requires a column definition and its column/cell gates,
including secondary definitions that the consumer does not render.
Custom/display-only accessors cannot manufacture write permission. Unexpected
transport errors are unconfirmed, not proof of rollback, and never trigger an
automatic second write.

- Double-click or keyboard Enter/F2 starts editing. Enter submits a single-line
  draft, Escape cancels an unsubmitted draft. IME composition and open selection
  controls consume Enter appropriately; multiline editors use their documented
  modified-key submission. Blur does not silently submit.
- One active cell editor per session initially. Moving to another cell with a
  dirty draft requires submit or cancel; it must not silently discard the draft.
  A session is keyed by row/column identity, never by virtual index.
- Validate locally on submit. Invalid drafts stay open with field errors. Email
  formatting and select membership can be checked locally; assignment permission,
  existence, uniqueness and business rules remain server responsibilities.
- Pin the active editor or render a stable editor host while its draft's row
  changes group/order/filter membership. Revoked access destroys protected drafts.
  One identity-only native query per active draft establishes row visibility
  independently of those view constraints, retaining mandatory table scopes.
  Unknown visibility hides moved editors and blocks writes. Empty results hide
  protected values, but pending writes retain outcome observation through optimistic
  disappearance. Failed or uncertain writes renew the identity query before
  deciding whether to restore or discard the draft. The original row is never an
  authorization fallback. `table.editingVisibility` and optional
  `DataTable.EditingStatus` expose redacted Retry/Cancel recovery and generic hidden
  outcomes outside virtual rows; pending writes cannot be cancelled.
  A remote value change does not overwrite a dirty draft; expose it as a conflict
  to resolve. No automatic merge or server compare-and-swap is implied.
- Submission remains pending until authoritative acceptance, not just optimistic
  local visibility. An accepted write with an unreadable/malformed returned result
  is accepted with a warning, not an invitation to replay the mutation. Preserve
  draft/error context for rejection or uncertainty. After enqueue, cancelling a
  UI operation cannot cancel or undo the write.
- V1 includes text, email, number, date, checkbox, static select, relation and a
  custom-editor contract. Read-only computed display columns are supported.

## 7. Formbraid and relation choices

Formbraid is optional; a consumer-owned `submit` callback and editor work without
it. The adapter reuses existing generated controller forms and error/submission
semantics. It derives supported field metadata and validation rather than creating
one form per display cell. A full-record request is not a cell-patch request:
required siblings and cross-field rules must still be satisfied explicitly.

A relation editor uses the shared Zero/Formbraid choice-source contract:

- `list({ search, cursor, signal })` loads authorized searchable choices infinitely.
- `resolve(ids, { signal })` resolves current selected IDs independently of the
  first/search page. It does not broaden choice authorization.
- Options have stable IDs and labels; the editor submits the ID, not a choice
  object. Clearing a nullable relation submits null, not an empty ID string.
- Candidate visibility and permission to assign that candidate are separate.
  Server validation must reject a visible but non-assignable ID.
- Query identity includes actor/context and search. Ignore stale results, cancel
  obsolete searches, and clear protected option caches on access loss.

The implementation uses Formbraid **0.3** and the shared
`@nickwelsh/zero-for-laravel/react/formbraid` option source. Its optional infinite
extension exposes `hasMore` and `loadMore` for server-declared infinite queries;
the browser cannot broaden a fixed query. `editors.relation(listOptions)` binds
the table editor to that source rather than creating a parallel provider. Upgrade
the PHP and JavaScript Formbraid packages together and regenerate descriptors.
The earlier inline-query sketch and Formbraid 0.2 compatibility gate are superseded
by this shared source binding.

## 8. Implementation sequence and acceptance gates

Each milestone is independently verifiable. Do not expand the public surface to
cover unsupported cases just to finish a milestone. Suggested new directories
below are ownership boundaries, not a requirement to create one class per noun.

| Milestone                                            | Implementation ownership                                                                                                                                                       | Acceptance gate                                                                                                                                                                        |
| ---------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 1. Shared table definition and predicate parity      | New `packages/laravel/src/Tables/`; reuse `Query/Condition`, model metadata, `Permissions/EloquentVisibility` and `ZeroVisibility`; add to `config/zero.php`                   | Same authorized row membership through both lowerings for every admitted operator; malformed/unsupported declarations fail explicitly                                                  |
| 2. Generated native rows and SQL metadata            | `Generation/`, `Console/GenerateCommand.php`, `Query/RegisteredQueries` integration, service-provider routes, generated manifest; focused tests under `packages/laravel/tests` | Registered tables generate typed descriptors, live rows and batched metadata; no-table output unchanged; `--check` detects stale artifacts; adversarial inputs cannot widen visibility |
| 3. New npm package and session lifecycle             | New `packages/table/` with root and React entry points; existing client changes only for genuinely reusable public selection contracts                                         | Typed state/actions, independent expansion, retained windows, metadata cancellation and authorization-safe cached revisits work without any table UI                                   |
| 4. Virtualized hooks and unstyled components         | `packages/table/src/react/`; move/generalize the spike's viewport logic only after lifecycle is correct                                                                        | Both scroll roots, variable row heights, independent open leaves, load/error entries, keyboard focus and scroll anchors work with bounded rendered ranges                              |
| 5. Cell editors and optional Formbraid bridge        | `packages/table` editor definitions/React state and isolated Formbraid entry point; existing generated mutation/form contracts                                                 | Edit gates, validation, acceptance/rejection/uncertainty, offscreen drafts and moving rows work; shared relation-source compatibility gate passes                                      |
| 6. Consumer migration, proof and release preparation | Replace example-only hooks/controller branching with a registered table; retain example-local fixtures; add consumer docs and package checks                                   | Todos consumes public APIs; large-data real-stack scenarios pass; package imports/types/dependencies are correct; documentation states snapshot/cache limits                           |

### Backend correctness and security cases

Keep focused public-interface tests for validation, permissions, metadata and
generated contracts. Use disposable PostgreSQL data, not shared/production tables.
Live membership parity tests must execute native Zero after synchronization, not
compare one compiler's output to itself. Compare against independently specified
IDs/counts in asymmetric fixtures.

- Cross-tenant/actor isolation; an OR matching a forbidden row; related labels or
  counts for inaccessible data; revoked permissions; stale contract and cursor.
- Null versus empty string versus false/zero; nullable negative comparisons;
  literal `%`, `_` and backslash; mixed case, non-ASCII/combining characters;
  date offsets and supported precision; permitted/forbidden aliases and operators.
- A group absent from the first row prefix; null and unavailable relation buckets;
  both sides of the 50-group directory boundary; more than one batched parent;
  count totals unaffected by row windows and display-only relationships.
- Group-path tampering, repeated dimensions, depth/term/list bounds, oversized
  windows, unknown descriptor/table and cross-actor cursor reuse.
- Stable mixed-direction row ordering with ties and nulls; correct exhaustion only
  after native completion; no count-to-exhaustion shortcuts.

### Runtime, UI and edit proof

Extend existing contract/lifecycle test patterns for public behavior. Keep unit
tests for subtle repeatable regressions; use disposable browser scripts for large
exploration rather than committing scripts that only inspect private hook state.
Capture and inspect rendered states and real pointer/keyboard interactions.

- Exercise the existing 54,750-row / 200-list fixture without reseeding it for
  every check. First cover at least eight expanded siblings and both root groups,
  then all 200 child groups. Opening one must not close, disable or reset another.
- Revisit loaded prefixes after child/parent collapse, virtual unmount and offline
  transition. Verify zero initial-loading transitions where access is still valid;
  separately prove required protected-access gating is not bypassed.
- Record active subscriptions, warmed resources, loaded prefixes, DOM counts,
  frame timing and memory while scrolling, collapsing and revisiting. Warming
  alone stays within four additional leaves; expanded demand is not capped.
  With one 1,000-row leaf at 1280×900 CSS pixels / DPR 2, render fewer than
  80 row elements when no exceptional editor pins are present. Opening 200 groups
  must not mount every loaded row; report actual resource costs without claiming
  fixed memory for unlimited expanded queries.
- Insert/delete/move rows around prefix boundaries; edit a group or sort field;
  confirm no duplicate/gapped concatenated-page behavior or viewport reset.
  Test scroll-anchor stability while earlier groups load additional rows, including
  clicking a later header while layout is changing.
- Cover cold, cached, refreshing, empty, validation-error, access-denied and
  metadata-failure states; wide/narrow rendering and window/panel scrolling.
- Exercise global/row/column/cell read-only gates, invalid email, select keyboard
  interaction/IME, server rejection, accepted-but-unreadable result, transport
  uncertainty, concurrent remote edits, and an editor moving out of the viewport.
- Resolve a relation outside the first choice page; reject a non-assignable ID;
  change search/actor during an in-flight request without leaking old choices.

### Repository checks and delivery

Run the existing JS/PHP test suites relevant to each changed layer, generated
contract checks, example types, formatting/lint, production build and package
import checks. Extend their fixtures rather than inventing a second test framework.
Check the new root/React/Formbraid entry points independently, including a consumer
without Formbraid installed. Add new-package coverage to `scripts/check-package.mjs`
and the workspace workflows where needed, without changing unrelated tooling.

Finish with the real consumer example, inspected screenshots/interaction clips,
recorded stress results and documented limitations. Preserve the existing spike
until its public-package replacement passes the same scenarios. No push,
publication, merge or deployment follows automatically; those require the user's
normal explicit delivery authorization.

## 9. Deferred scope and implementation decisions

Not in v1: many-to-many groups, computed/date-bucket groups, sort-by-relation/count,
continuously live exact totals, arbitrary SQL/Eloquent callback translation,
offset/random-index paging, memory-bounded unlimited scrolling, a non-React live
engine, URL persistence, range selection, clipboard/paste/fill, formulas, drag/drop
reordering or a themed component kit. These need separate contracts.

The following are implementation gates, not permission to guess silently:

- Shared Formbraid option-source availability and compatible dependency versions.
- Proven cross-engine text/date semantics; narrow or explicitly revise an operator
  contract if its intended meaning cannot be implemented identically.
- A clean native registration seam for tables in the existing controller-first
  compiler. Do not make the controller compiler execute arbitrary table PHP or
  bypass registered-query middleware/access checks.
- Performance costs with all 200 groups expanded. Surface resource pressure;
  discuss a product retention policy if needed instead of silently closing groups.

## 10. Evidence and starting points

The local consumer verification on 2026-11-04 used the existing 54,750-row fixture,
the generated Todos table descriptor, real Laravel/PostgreSQL/Zero, and a
production-built React Compiler consumer. It established:

- All 200 Active child groups and one Completed child group simultaneously
  subscribed: 201 active leaves, four warmed leaves, 205 retained windows.
- A 1,000-row first-leaf prefix plus its extra-row probe; 11,401 total loaded
  rows across active/warm leaves, with 14–23 row elements rendered at 1280×900.
- Collapsing/reopening the parent retained all 11,000 displayed rows. An offline
  collapse/reopen also produced zero initial-loading entries while access remained valid.
- Settled idle animation-frame intervals measured 16.7 ms median and 16.8 ms
  p95 over 120 frames. This is not a measurement of scrolling or burst hydration.
- Real double-click/Enter editing, local required validation, authoritative save
  and database persistence. Relation search retained the independently resolved
  selected label outside the searched page. Window and panel scrolling rendered
  real rows; the panel recycled offscreen DOM.
- Native/SQL membership matched 19 independently enumerated cases, including
  literal wildcard characters, case, Unicode, offset/millisecond dates and an OR
  branch that could otherwise expose another actor's or a soft-deleted row.

Bulk-expansion follow-up on 2026-11-05 used the same real stack and fixture at
1280×900, DPR 2, on the two-CPU / 4 GB orb. Each fresh browser loaded all 200 Active
directory entries, then called public `expand()` for every child in one task.
Each leaf requested 50 rows plus one exhaustion probe. Three completed runs on
each build reached 200 live leaves, 10,200 loaded rows and 14 rendered row elements:

- Median synchronization time: 40.15 seconds before, 12.62 seconds after.
- Median peak largest-renderer RSS, sampled externally every 200 ms: 2.53 GB
  before, 1.28 GB after. RSS is process resident memory, not retained JS heap.
- Median longest task: 13.66 seconds before, 2.07 seconds after. After-run longest
  tasks ranged from 1.38 to 2.54 seconds; burst hydration is still not smooth.

The session now publishes leaf results once per microtask rather than flattening
and notifying for every leaf result. Memoized query owners still receive native
updates and explicit prefix-limit changes. Viewport/hover changes only publish
when query demand changes; rendered cells reuse already-flattened row identities
instead of recomputing every loaded primary key for each cell. None of these
changes caps expansion, closes siblings or adds a canonical-row cache.

With the first prefix grown to 1,000 rows, all 200 groups retained 11,150 loaded
rows. A scripted 30-frame scroll sweep from 10,000 to 40,000 CSS pixels and back
recorded a 717 ms median frame interval before the cell/viewport changes. Three
after sweeps recorded medians of 17–24 ms and p95 intervals of 28–41 ms, with at
most 31 rendered row elements. Only one completed baseline sweep was saved; this
is not a three-run before/after scrolling comparison. Offline parent collapse and
reopen retained that prefix with zero initial live-row loading transitions.
Metadata refresh can still display the expected offline fetch error. Editing
cancel/focus and independent warming/expansion were also rechecked.

A sampling profile of the batched build attributed most transient allocations to
native Zero row propagation through its active query connections, not retained
table rows. The measured reduction does not establish bounded memory, production
latency, or smooth cold hydration of hundreds of queries. Further native fan-out
tuning or a product retention policy remains a separate decision if the remaining
resource use is unacceptable. Failed browser launches and recording retries were
excluded from the measurements; they did not replace a completed benchmark run.

Native hydration investigation on 2026-11-05 isolated the remaining synchronous
work without changing expansion policy. The installed `@rocicorp/zero@1.11.3`
`memory-source.js` matched the published npm tarball; upstream `main` also retained
the same propagation loop when inspected. The path is
`ZeroContext.processChanges → IVMSourceBranch.advance → applyDiffs → pushAdds`.
The existing 12 ms startup hydration slices yield between pending pipelines, not
between incoming rows. `pushAdds` consumes each row's generator synchronously.
Each row visits every connection, constructs change objects before filtering,
and then reconciles every connected output, including nonmatching queries.

Three fresh-browser runs per variant used the same production consumer and
54,750-row fixture. Timing instrumentation covered native branch advancement and
each `pushAdds` call; both variants used identical instrumentation. Every completed
run retained 200 active leaves and 10,200 loaded rows. In each run the largest
native add burst contained 9,996 rows with 200 connections:

- Published Zero: median longest task 1,802 ms, largest add burst 1,272 ms,
  branch advancement 1,274 ms, total synchronization 12.62 seconds.
- Experimental early rejection of nonmatching adds/removes, before allocating
  change wrappers: corresponding medians 1,772 ms, 1,320 ms, 1,321 ms and
  12.78 seconds. This does not establish a browser improvement. One completed
  experimental run took 23.28 seconds overall; it remains in the results.

An isolated Node experiment using real `MemorySource` and `Take` operators,
10,200 fixed incoming rows and 1, 20 or 200 filtered connections measured median
propagation times of 70, 184 and 1,317 ms. At 200 connections it counted 2,040,000
terminal reconciliation calls for 10,200 matching row pushes. Both published and
experimental implementations passed 6,160 independently checked state comparisons
covering limited sorted prefixes, filter membership changes, cross-group and
split/unsplit edits, deletes/refills and source/fetch agreement. These component
checks do not establish correctness for all native joins or transaction lifecycles.
The allocation-only prototype was not adopted. Invalid harness attempts and one
run overlapping a separate benchmark were discarded and rerun in isolation.

Further work belongs in Zero's connection dispatch/reconciliation or transaction
scheduling, not another table-row cache. Candidate-connection indexing and
dirty-only reconciliation need native operator coverage before adoption. Simply
adding `await` inside `advance` is unsafe without serializing incoming changes,
query attachment and mutation/rebase activity, withholding commit publication,
and preserving branch-head/error semantics. No native dependency patch, expansion
cap or scheduling change was retained; cold hydration remains a responsiveness
limitation, not a solved performance case.

An indexed-dispatch follow-up on 2026-11-05 prototyped scalar equality buckets
inside Zero's `MemorySource`, with all reconciliation left unchanged. The index
uses necessary equalities from conjunctions, chooses a selective column, and
keeps other predicates on the ordinary fallback path. Native predicates still
decide membership. Edits visit the union of old/new buckets in original connection
order; connecting or disconnecting invalidates the cached index. The prototype
does not change global split-edit selection, branch advancement or publication.

Three new fresh-browser runs per build used the same fixture and instrumentation,
with no concurrent build or benchmark. All six reached 200 active leaves, 10,200
loaded rows and 14 rendered row elements. Published Zero versus indexed pushes:

- Median largest 9,996-row add burst: 1,390 → 824 ms (41% lower).
- Median longest browser task: 1,929 → 1,367 ms (29% lower).
- Median total synchronization: 13.07 → 12.76 seconds. The indexed build's
  27.65-second completed outlier was retained; overall synchronization remains
  variable, and this does not establish a meaningful end-to-end speedup.
- Median peak largest-renderer RSS sampled every 200 ms: 1.25 → 1.28 GB.
  No memory reduction was established. This is resident memory, not retained heap.

Both builds passed 28,512 comparisons against an independent array oracle,
including typed equality, AND/OR, null/range/text/IN fallback, old/new edit routing,
connection insertion/removal, late output attachment, deferred generators, forks,
sorted limits/refills and a three-level self-join with partitioned child limits.
Deliberately omitting the old edit bucket made the oracle fail. Live prefix growth
to 1,000 retained all 200 groups and 11,150 loaded rows; offline collapse/reopen
produced zero initial live-row loading transitions. A title edit propagated through
native rows and PostgreSQL, then the original fixture value was restored.

The isolated 200-query equality benchmark improved, but still counted 2,040,000
reconciliation calls for 10,200 row pushes. Non-indexable IN filters used the full
fallback path (median 1.46 → 1.54 seconds in three Node runs), so indexing is not
a general improvement for arbitrary queries. One failed browser launch was retried;
no completed timing run was dropped. The compiled-JavaScript prototype and its
reproducer are preserved as review artifacts, not an installed dependency patch.
The normal consumer build was restored. Native TypeScript integration and the full
upstream operator/lifecycle suite remain unverified. This improves the measured
stall but does not make cold hydration smooth; reconciliation remains separate work.

The spike established feasibility of live leaves with SQL snapshot directories,
increasing prefixes, proactive caching, independent expansion, and window/panel
virtualization. Its earlier multiple-expansion browser check exercised eight Active
and two Completed leaves on the large fixture, including offline cached reopening
with zero initial-loading transitions. At that stage it did not prove the reusable
API, all 200 expanded groups, general filter parity, or the proposed editor system.

Implementation starting points:

- PHP: `packages/laravel/src/Query/ZeroQuery.php`, `Query/QueryCompiler.php`,
  `Query/RegisteredQuery.php`, `Generation/ControllerQueryCompiler.php`,
  `Console/GenerateCommand.php`, and `Permissions/EloquentVisibility.php`.
- Client: `packages/client/src/react/data.ts`, `react/access.tsx`, `live-query.ts`,
  `formbraid.ts`, and existing lifecycle/generated-contract tests. Protected access
  generations intentionally restrict rendering cached/partial rows.
- Spike: `examples/todos/app/Http/Controllers/GroupedTodoController.php`,
  `resources/js/hooks/use-grouped-todos.ts`, `use-group-virtualizer.ts`,
  `resources/js/pages/grouping.tsx`, `tests/Feature/GroupedTodosTest.php`, and
  `database/seeders/GroupingStressSeeder.php`.
- External constraints: [Zero ZQL](https://zero.rocicorp.dev/docs/zql),
  [Zero caching](https://zero.rocicorp.dev/docs/reading-data#query-caching),
  [Spatie filter vocabulary](https://spatie.be/docs/laravel-query-builder/v6/features/filtering),
  and [TanStack manual grouping](https://tanstack.com/table/v8/docs/guide/grouping).
