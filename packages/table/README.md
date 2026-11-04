# Zero Table

Headless tables for native Zero rows and authorized Laravel snapshot group counts.
Register a fluent table definition with `zero.tables`, run `zero:generate`, and
import its generated descriptor. The React-free root exports descriptor types,
query lowering, filter validation and stable identities. `/react` owns sessions
and subscriptions using Zero for Laravel's existing authorization-aware `useData`.

```tsx
const table = useDataTable(tasks);
return <DataTable.Root table={table}>{/* Render table.items with your UI. */}</DataTable.Root>;
```

Mount exactly one DOM-free root per session. Query owners outlive virtual rows.
Expansions are independent; collapsing a parent preserves its expanded leaves.
Each leaf grows one live prefix, with an extra row proving whether more exists.
Snapshot counts never determine live exhaustion. A maximum of four additional
non-expanded leaves are proactively warmed; explicit expansions are not capped.

Counts and directories are snapshots, not live aggregates. Call `refreshCounts()`
to refresh them. Focus and reconnect also refresh. Metadata failures retain stale
authorized snapshots; denied access clears rows and metadata. Cold caches and
renewed protected access may require loading. Recently deactivated native queries
retain Zero's normal five-minute cache; there is no duplicate canonical-row cache.

DOM virtualization does not bound loaded prefixes or native retained queries.
`table.diagnostics` exposes active/warm leaves and requested/loaded rows without
row contents or credentials. Unlimited expansion has real memory and sync costs.

See the repository's `docs/TABLE_DESIGN.md` for the complete API contract and
the remaining virtualization, editor and example acceptance gates.
