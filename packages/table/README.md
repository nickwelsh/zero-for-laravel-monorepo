# Zero Table

Headless tables for native Zero rows and authorized Laravel snapshot group counts.
Register a fluent table definition with `zero.tables`, run `zero:generate`, and
import its generated descriptor. The React-free root exports descriptor types,
query lowering, filter validation and stable identities. `/react` owns sessions
and subscriptions using Zero for Laravel's existing authorization-aware `useData`.

Use table **0.1.0** with PHP/client **0.7.0** and Zero client/cache **1.11.3**.
Install `@nickwelsh/zero-table@^0.1.0` alongside client **^0.7.0** and regenerate
contracts after upgrading the PHP package. The optional Formbraid bridge requires
Formbraid **^0.3.0**; other entry points do not require Formbraid.

## Define capabilities once

Table definitions live in the existing Laravel package. Register the class under
`tables.tasks` in `config/zero.php`, then run `php artisan zero:generate`.

```php
public function definition(TableContext $context): Table
{
    return Table::for(Task::class)->visibleTo($context->user())
        ->allowedFilters([
            AllowedFilter::text('title')->operators(['contains', 'equals']),
            AllowedFilter::exact('completed'),
        ])
        ->allowedSorts(['title', 'created_at'])
        ->allowedGroups([AllowedGroup::field('completed')])
        ->defaultGroupBy(['completed'])->pageSize(50);
}
```

Only declared filters, operators, sorts and group paths are accepted. Mandatory
visibility wraps the entire user filter, including OR branches. Rows use native
Zero queries; directories and counts use an authenticated SQL snapshot endpoint.
The endpoint accepts table state, never arbitrary browser ZQL or SQL.

## Render with your own UI

```tsx
import { column, editors } from '@nickwelsh/zero-table';
import { DataTable, useDataTable } from '@nickwelsh/zero-table/react';
import tasks from './zero/tables/tasks';

const columns = [column.text('title', { editor: editors.text() })];

function Tasks() {
    'use no memo';
    const table = useDataTable(tasks, { columns });
    return (
        <DataTable.Root table={table}>
            <DataTable.Viewport scrollElement="window" aria-label="Tasks">
                {(item) => {
                    if (item.kind === 'group') return <DataTable.Group group={item} />;
                    if (item.kind === 'row')
                        return (
                            <DataTable.Row item={item}>
                                <DataTable.Cell rowId={item.id} columnId="title" />
                            </DataTable.Row>
                        );
                    if (item.kind === 'error')
                        return <button onClick={() => table.retry(item.id)}>{item.error.message}: retry</button>;
                    return item.kind === 'empty' ? 'No tasks' : 'Loading…';
                }}
            </DataTable.Viewport>
        </DataTable.Root>
    );
}
```

Wrap the consumer in the application's existing Zero provider and access boundary.
The session is mutable, like a TanStack virtualizer: React Compiler consumers that
read its properties must opt out with `'use no memo'`. Built-in subscription and
viewport components opt out internally. Components are unstyled; supply classes
or use `useTableViewport` and `useTableCell` to build your own grid. For element
scrolling, pass a ref to an overflow container instead of `"window"`.

Mount exactly one DOM-free root per session. Query owners outlive virtual rows.
Expansions are independent; collapsing a parent preserves its expanded leaves.
Each leaf grows one live prefix, with an extra row proving whether more exists.
Snapshot counts never determine live exhaustion. A maximum of four additional
non-expanded leaves are proactively warmed; explicit expansions are not capped.

`DataTable.Viewport` and `useTableViewport` accept `prefetchRows` (default `40`).
This loads more when a pagination marker is within that many flattened items of
the visible range, including group headers. It is independent of DOM `overscan`
(default `6`), so earlier fetching does not mount extra rows. Use `prefetchRows={0}`
to wait until the marker is visible. Pinned editing/focus rows do not move the
prefetch range. A continuation requested during synchronization finishes once
ready, even after scrolling away; it does not keep paging an offscreen group.
Slow connections and very fast scrolling can still expose a loading state.

### Optional background and hover loading

```tsx
const table = useDataTable(tasks, {
    preload: { maxRows: 1000, maxQueries: 8, batchSize: 100 },
    hover: 'preload', // false | 'load' (default) | 'preload'
});
```

`preload` defaults to `false`; `true` uses the budgets above. First pages stay at
the server-declared page size. After foreground queries settle, background loading
grows one expanded leaf at a time, sharing the budget across shorter prefixes
first. An ungrouped table is treated as one expanded leaf. A small result can
finish entirely; larger results stop at the budget and continue through normal
scroll demand. Native completion and extra-row probes determine exhaustion, never
snapshot counts. These are experimental budgets, not an automatic dataset-size
threshold or a byte-level memory limit.

`maxRows` includes all currently subscribed leaf windows and their extra-row probes,
including viewport-warmed and manually expanded leaves. Background growth stops
when that budget is spent or the number of subscribed leaves exceeds `maxQueries`.
Neither budget caps explicit expansion or scrolling, discards already loaded rows,
nor bounds Zero's retained query cache. Turning preloading off stops future growth;
an already requested native window may finish.

`DataTable.Group` waits for 150ms of pointer dwell before declaring hover intent;
keyboard focus declares intent immediately. `'load'` warms a first page, while
`'preload'` also grows recent hover-intent leaves under the same shared budgets,
even when `preload` is false. Hovering a non-leaf group fetches its first child
directory and prioritizes up to four child leaves; it does not recursively expand
or page through every group. `hover: false` disables hover/focus intent, not normal
viewport warming. Headless consumers can call `table.warm(groupId)` themselves.

Background growth waits for 150ms without scrolling, pointer-down or keyboard
activity, and pauses while editing, offline, hidden, or waiting for foreground
queries. Scope changes and access loss cancel scheduled growth. Already requested
scroll continuations still finish when their synchronization completes.

Counts and directories are snapshots, not live aggregates. Call `refreshCounts()`
to refresh them. Focus and reconnect also refresh. Metadata failures retain stale
authorized snapshots; denied access clears rows and metadata. Cold caches and
renewed protected access may require loading. Recently deactivated native queries
retain Zero's normal five-minute cache; there is no duplicate canonical-row cache.

DOM virtualization does not bound loaded prefixes or native retained queries.
`table.diagnostics` exposes active/warm leaves and requested/loaded rows without
row contents or credentials. Unlimited expansion has real memory and sync costs.

## Enable editing explicitly

Pass `editing: { enabled, submit, canEditRow, canEditColumn, canEditCell }` to
`useDataTable`. All supplied gates must allow the cell. A gate cannot grant server
authorization. Columns without editors stay read-only. Built-ins include text,
email, number, date, checkbox and static select; custom and relation editors use
`editing.renderEditor`. Composite editors must declare every patch field and all
those fields must have column definitions and pass the same gates. A secondary
definition need not be rendered, but omitting its definition rejects the patch.

Double-click, Enter or F2 begins editing; Enter submits, Escape cancels, and blur
does not save. Multiline editors submit with Ctrl/Command+Enter. Select controls
consume Enter themselves. Drafts survive virtual unmount and row moves; a dirty
draft blocks switching cells. Concurrent live changes are reported as conflicts,
not silently merged. Local format validation supplements server validation.

The active draft owns one additional identity-only native row query, independent
of the current filter/group/window but subject to the table's mandatory scopes
and visibility. A moved editor is hidden and cannot submit until that read proves
visibility. Empty results hide protected content immediately, but a pending write
remains observed until it settles: optimistic disappearance is not proof of server
acceptance or rejection. Failed or uncertain submissions require a fresh identity
read before the draft can reappear. Confirmed absence discards protected values;
access denial clears the session. This one-row subscription is separate from the
leaf preloading budgets and ends when editing ends. No original-row fallback can
stand in for current authorization.

Render `DataTable.EditingStatus` inside `DataTable.Root` (outside the virtualized
rows), or render `table.editingVisibility` yourself. This redacted recovery surface
provides Retry and Cancel when the identity query fails while an editor is hidden,
and generic write outcomes without disclosing row values or server error details.
Pending writes cannot be cancelled; dismissing an unconfirmed outcome does not undo
or retry the write. Check the record before submitting again.

`submit(change)` receives the original row, field, value and patch. Return
`{ status: 'accepted' }`, `{ status: 'invalid', errors }`,
`{ status: 'rejected', error }` or `{ status: 'unconfirmed', error }`.
Acceptance may include a warning. Unknown outcomes never retry automatically;
check the record before attempting another write. Authorization loss clears
protected drafts as well as rows and metadata.

The optional `@nickwelsh/zero-table/formbraid` entry exports `editorFromForm`,
`formbraidSubmit` and `RelationEditor`, compatible with Formbraid 0.3. Map a cell
patch to the full generated request explicitly: required siblings are not inferred.
Relation editors reuse the existing generated Zero option sources, independently
resolve selected IDs, search authorized choices, and grow server-declared infinite
queries. Pass an `->infinite(20)` query to `ZeroOptions::query(...)` in the source
definition; a browser cannot turn a fixed query into an unlimited one. Submit IDs, not labels.
Candidate visibility does not imply assignment permission.

The root entry has no React or Formbraid runtime imports. `/react` does not require
Formbraid; only `/formbraid` imports the optional adapter dependencies.

See `examples/todos/resources/js/pages/grouping.tsx` for a real generated-descriptor
consumer, including full-request mutation mapping and window/panel scrolling.
`docs/TABLE_DESIGN.md` records the detailed contract and release acceptance gates.
