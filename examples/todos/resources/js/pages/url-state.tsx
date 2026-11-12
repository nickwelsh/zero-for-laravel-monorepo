import { Head, usePage } from '@inertiajs/react';
import { createPageURLState, usePageURLState } from '@nickwelsh/zero-for-laravel/react';
import { inertiaURLAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import { column, type TableState } from '@nickwelsh/zero-table';
import { DataTable, tableStateCodec, useDataTable } from '@nickwelsh/zero-table/react';
import { ZeroContext } from '@rocicorp/zero/react';
import { useContext, useState } from 'react';
import todos from '@/zero/tables/todos';

type State = TableState<typeof todos.capabilities>;
type Sources = { active: State; completed: State };
const columns = [column.text('title', { header: 'Title' }), column.boolean('completed', { header: 'Done' })];

export default function URLState({
    initialInputs,
    initialURL,
    defaults,
}: {
    initialInputs: Sources;
    initialURL: string;
    defaults: Sources;
}) {
    const { url } = usePage();
    const zero = useContext(ZeroContext);
    const [page] = useState(() =>
        createPageURLState(
            typeof window === 'undefined' ? { ...inertiaURLAdapter, getURL: () => url } : inertiaURLAdapter,
            {
                active: tableStateCodec(todos.capabilities, { key: 'table.active', initialState: defaults.active }),
                completed: tableStateCodec(todos.capabilities, {
                    key: 'table.completed',
                    initialState: defaults.completed,
                }),
            },
            { url: initialURL, state: initialInputs },
        ),
    );
    const state = usePageURLState(page);
    return (
        <main className="space-y-6 p-6">
            <Head title="URL state" />
            <h1 className="text-2xl font-semibold">Two independent live sources</h1>
            <p>
                Search replaces history after 250 ms. Sort and reset push a history entry. Back/Forward restores both
                sources.
            </p>
            {(['active', 'completed'] as const).map((key) => (
                <section key={key} aria-label={key} className="space-y-3">
                    <h2 className="text-lg font-semibold capitalize">{key} defaults</h2>
                    <div className="flex items-center gap-3">
                        <label>
                            Search {key}
                            <input
                                className="ml-2 rounded border p-2"
                                aria-label={`Search ${key}`}
                                value={
                                    state[key].filter?.kind === 'and'
                                        ? String(
                                              state[key].filter.children[1]?.kind === 'condition'
                                                  ? state[key].filter.children[1].value
                                                  : '',
                                          )
                                        : ''
                                }
                                onChange={(event) => {
                                    const text = event.target.value;
                                    page.set(
                                        key,
                                        (previous) => ({
                                            ...previous,
                                            filter: text
                                                ? {
                                                      kind: 'and',
                                                      children: [
                                                          defaults[key].filter!,
                                                          {
                                                              kind: 'condition',
                                                              field: 'title',
                                                              operator: 'contains',
                                                              value: text,
                                                          },
                                                      ],
                                                  }
                                                : defaults[key].filter,
                                        }),
                                        { debounceMs: 250 },
                                    );
                                }}
                            />
                        </label>
                        <button
                            className="rounded border p-2"
                            onClick={() =>
                                page.set(
                                    key,
                                    (previous) => ({
                                        ...previous,
                                        sort: [
                                            {
                                                field: 'title',
                                                direction: previous.sort[0]?.direction === 'desc' ? 'asc' : 'desc',
                                            },
                                        ],
                                    }),
                                    { history: 'push' },
                                )
                            }
                        >
                            Sort {key}
                        </button>
                        <button className="rounded border p-2" onClick={() => page.reset(key, 'push')}>
                            Reset {key}
                        </button>
                    </div>
                    <output aria-label={`${key} state`} className="block break-all text-xs">
                        {JSON.stringify(state[key])}
                    </output>
                    {zero ? (
                        <Source state={state[key]} onStateChange={(next) => page.set(key, next)} />
                    ) : (
                        <p>Starting live data…</p>
                    )}
                </section>
            ))}
        </main>
    );
}

function Source({ state, onStateChange }: { state: State; onStateChange: (next: State) => void }) {
    'use no memo';
    const table = useDataTable(todos, { state, onStateChange, columns });
    return (
        <DataTable.Root table={table}>
            <DataTable.Viewport table={table} scrollElement="window" className="rounded border">
                {(item) =>
                    item.kind === 'row' ? (
                        <DataTable.Row item={item} className="flex justify-between border-b p-3">
                            <DataTable.Cell table={table} rowId={item.id} columnId="title" />
                            <DataTable.Cell table={table} rowId={item.id} columnId="completed" />
                        </DataTable.Row>
                    ) : item.kind === 'group' ? (
                        <DataTable.Group table={table} group={item} className="w-full border-b p-3 text-left">
                            {item.label}
                        </DataTable.Group>
                    ) : (
                        <p className="p-3">
                            {item.kind === 'error'
                                ? item.error.message
                                : item.kind === 'empty'
                                  ? 'No matching todos'
                                  : 'Loading…'}
                        </p>
                    )
                }
            </DataTable.Viewport>
        </DataTable.Root>
    );
}
