import { Head } from '@inertiajs/react';
import { column, editors, type CellChange } from '@nickwelsh/zero-table';
import { DataTable, useDataTable } from '@nickwelsh/zero-table/inertia';
import { editorFromForm, formbraidSubmit, RelationEditor } from '@nickwelsh/zero-table/formbraid';
import { ZeroContext } from '@rocicorp/zero/react';
import { useContext, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { update } from '@/formbraid/zero/App/Http/Controllers/TodoController';
import { SaveTodoRequest } from '@/formbraid/zero/requests/App/Http/Requests/SaveTodoRequest';
import type { TodoRow } from '@/zero/generated';
import todos from '@/zero/tables/todos';

const form = update({ todo: '' });
const relation = SaveTodoRequest.fields.list_id.options;
const columns = [
    column.text('title', {
        header: 'Title',
        editor: editorFromForm(SaveTodoRequest.fields.title)!,
        query: { filter: 'title', sort: 'title' },
    }),
    column.boolean('completed', {
        header: 'Done',
        editor: editors.checkbox(),
        query: { filter: 'completed', group: 'completed' },
    }),
    column.relation('list_id', {
        header: 'List',
        editor: editors.relation({
            source: form.optionSources.bindings[relation.id],
            inputs: relation.inputs,
            resolveInputs: relation.resolveInputs,
        }),
        query: { filter: 'list', group: 'list' },
    }),
];
const submit = formbraidSubmit({
    form: (change: CellChange<TodoRow>) => update({ todo: change.row.id }),
    // This generated form has a full-record output contract. Required siblings
    // are explicit; a generic cell patch must not pretend to be a complete form.
    values: (change: CellChange<TodoRow>) => ({
        title: change.row.title,
        completed: change.row.completed,
        list_id: change.row.list_id,
        ...change.patch,
    }),
});

export default function Grouping() {
    // The Inertia layout renders its shell before native Zero initialization.
    const zero = useContext(ZeroContext);
    return zero ? <TodosTable /> : <output className="p-6">Starting live data…</output>;
}

function TodosTable() {
    'use no memo';
    const [editable, setEditable] = useState(false);
    const [panelMode, setPanelMode] = useState(false);
    const [prefetchRows, setPrefetchRows] = useState(40);
    const [preload, setPreload] = useState(false);
    const [hover, setHover] = useState<false | 'load' | 'preload'>('load');
    const panel = useRef<HTMLDivElement>(null);
    const table = useDataTable(todos, {
        urlState: true,
        columns,
        preload,
        hover,
        editing: {
            enabled: editable,
            submit,
            renderEditor: (cell) =>
                cell.column.editor?.kind === 'relation' ? <RelationEditor cell={cell} /> : undefined,
        },
    });
    return (
        <div className="space-y-4 p-4 md:p-6">
            <Head title="Data table" />
            <header className="space-y-3">
                <h1 className="text-2xl font-semibold">Todos data table</h1>
                <p className="text-sm text-muted-foreground">
                    Live rows · snapshot group counts · double-click or Enter to edit
                </p>
                <div className="flex flex-wrap items-center gap-3">
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={editable}
                            onChange={(event) => setEditable(event.target.checked)}
                        />
                        Enable editing
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={panelMode}
                            onChange={(event) => setPanelMode(event.target.checked)}
                        />
                        Panel scrolling
                    </label>
                    <Button variant="outline" onClick={() => table.refreshCounts()}>
                        Refresh counts
                    </Button>
                    <label>
                        Title filter
                        <input
                            aria-label="Title filter"
                            className="ml-2 rounded border px-2 py-1"
                            value={
                                table.state.filter?.kind === 'condition' && table.state.filter.field === 'title'
                                    ? String(table.state.filter.value ?? '')
                                    : ''
                            }
                            onChange={(event) =>
                                table.setFilter(
                                    event.target.value
                                        ? {
                                              kind: 'condition',
                                              field: 'title',
                                              operator: 'contains',
                                              value: event.target.value,
                                          }
                                        : null,
                                )
                            }
                        />
                    </label>
                    <label>
                        Order
                        <select
                            aria-label="Order"
                            className="ml-2 rounded border px-2 py-1"
                            value={table.state.sort[0]?.field ?? 'created_at'}
                            onChange={(event) =>
                                table.setSort([
                                    {
                                        field: event.target.value === 'title' ? 'title' : 'created_at',
                                        direction: 'asc',
                                    },
                                ])
                            }
                        >
                            <option value="created_at">Created</option>
                            <option value="title">Title</option>
                        </select>
                    </label>
                    <label>
                        Lookahead rows
                        <input
                            type="number"
                            min={0}
                            step={1}
                            defaultValue={40}
                            className="ml-2 w-20 rounded border px-2 py-1"
                            title="Prefetch this many items around the viewport. 0 loads only when visible."
                            onChange={(event) => {
                                const value = event.target.valueAsNumber;
                                if (Number.isSafeInteger(value) && value >= 0) setPrefetchRows(value);
                            }}
                            onBlur={(event) => {
                                event.currentTarget.value = String(prefetchRows);
                            }}
                        />
                    </label>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={preload}
                            onChange={(event) => setPreload(event.target.checked)}
                        />
                        Background preload
                    </label>
                    <label>
                        Hover loading
                        <select
                            className="ml-2 rounded border px-2 py-1"
                            value={hover || 'off'}
                            onChange={(event) =>
                                setHover(
                                    event.target.value === 'preload'
                                        ? 'preload'
                                        : event.target.value === 'load'
                                          ? 'load'
                                          : false,
                                )
                            }
                        >
                            <option value="off">Off</option>
                            <option value="load">First page</option>
                            <option value="preload">Bounded preload</option>
                        </select>
                    </label>
                </div>
                {(preload || hover === 'preload') && (
                    <p className="text-xs text-muted-foreground">
                        Background budget: 1,000 requested rows (including probes) · up to 8 leaf queries · batches of
                        100. Pauses while scrolling or editing. Disabling does not discard loaded rows.
                    </p>
                )}
                <output className="block text-sm" aria-live="polite">
                    {table.count.value ?? '…'} matching todos ·{' '}
                    {table.count.pending
                        ? 'Refreshing counts'
                        : table.count.stale
                          ? 'Counts may be stale'
                          : `Observed ${table.count.observedAt ?? '—'}`}
                </output>
                {table.count.error && <p role="alert">{table.count.error.message}</p>}
                <output className="block break-all text-xs text-muted-foreground" data-table-diagnostics>
                    {JSON.stringify(table.diagnostics)}
                </output>
            </header>
            <DataTable.Root table={table}>
                <DataTable.EditingStatus className="rounded border p-3 text-sm [&_button]:ml-3 [&_button]:underline" />
                <div
                    ref={panel}
                    className="rounded-lg border"
                    style={panelMode ? { height: 600, overflow: 'auto' } : {}}
                >
                    <DataTable.Viewport
                        table={table}
                        scrollElement={panelMode ? panel : 'window'}
                        aria-label="Todos"
                        estimateSize={52}
                        prefetchRows={prefetchRows}
                    >
                        {(item) => {
                            if (item.kind === 'group')
                                return (
                                    <DataTable.Group
                                        table={table}
                                        group={item}
                                        className="flex w-full items-center gap-3 border-b bg-muted px-4 py-3 text-left font-medium"
                                        style={{ paddingLeft: 16 + item.depth * 24 }}
                                    >
                                        <span>{item.expanded ? '▾' : '▸'}</span>
                                        <span>
                                            {item.path.at(-1)?.field === 'completed'
                                                ? item.path.at(-1)?.value
                                                    ? 'Completed'
                                                    : 'Active'
                                                : item.label}
                                        </span>
                                        <span className="ml-auto text-sm tabular-nums text-muted-foreground">
                                            {item.count.value ?? '…'}
                                        </span>
                                    </DataTable.Group>
                                );
                            if (item.kind === 'row')
                                return (
                                    <DataTable.Row
                                        item={item}
                                        className="grid grid-cols-[minmax(0,1fr)_5rem_minmax(7rem,0.4fr)] items-start gap-3 border-b px-4 py-3 text-sm [&_[role=gridcell]]:break-words [&_input]:max-w-full [&_input]:rounded [&_input]:border [&_input]:px-2 [&_button]:mx-1"
                                    >
                                        <DataTable.Cell table={table} rowId={item.id} columnId="title" />
                                        <DataTable.Cell table={table} rowId={item.id} columnId="completed" />
                                        <DataTable.Cell table={table} rowId={item.id} columnId="list_id" />
                                    </DataTable.Row>
                                );
                            return (
                                <div className="p-3 text-sm text-muted-foreground">
                                    {item.kind === 'error' ? (
                                        <>
                                            <span role="alert">{item.error.message}</span>
                                            <Button onClick={() => table.retry(item.id)}>Retry</Button>
                                        </>
                                    ) : item.kind === 'empty' ? (
                                        'No todos in this group.'
                                    ) : (
                                        'Loading…'
                                    )}
                                </div>
                            );
                        }}
                    </DataTable.Viewport>
                </div>
            </DataTable.Root>
        </div>
    );
}
Grouping.layout = { breadcrumbs: [{ title: 'Data table', href: '/grouping' }] };
