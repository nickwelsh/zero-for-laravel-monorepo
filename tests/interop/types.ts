import { useData, type QueryData } from '@nickwelsh/zero-for-laravel/react';
import type {
    InferLiveQueryResult,
    InferQueryInput,
    InferQueryResult,
    InferMutationInput,
} from '@nickwelsh/zero-for-laravel';
import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { createControllerForm, type ControllerFormRuntime } from '@nickwelsh/zero-for-laravel/formbraid';
import { defineForm } from '@formbraid/core';
import type { Schema, Zero } from '@rocicorp/zero';
import type { ClientContext } from '@nickwelsh/zero-for-laravel';
import type { choices } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { index, show, dated } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { store } from './controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';
import { search, detail } from './controller-api/ZeroForLaravel/Tests/Fixtures/Module/TaskQueries';
import type { schema, Context } from './generated';
import tasks from './table.generated';
import { useDataTable } from '@nickwelsh/zero-table/react';
import { column, editors, type CellChange } from '@nickwelsh/zero-table';

// Compiled by typecheck; these exercise only public generated controller contracts.
function useContracts() {
    const result = useData(index.tasks.live({ title: 'Hello' }));
    const rows: InferLiveQueryResult<typeof index.tasks> = result.data;
    const date: Date | null | undefined = rows[0]?.completed_at;
    const selected = useData(show.task.live({ task: 'id' }));
    const title: string | undefined = selected.data?.title;
    const save = useControllerMutation(store);
    const input: InferMutationInput<typeof store> = { title: 'Hello' };
    const saved: Promise<{ id: string }> = save(input);
    // @ts-expect-error hidden columns never appear in the generated schema
    rows[0]?.secret;
    // @ts-expect-error controller route selectors keep their types
    show.task({ task: 42 });
    // @ts-expect-error trusted ownership is not a controller request field
    void save({ title: 'Hello', user_id: 'forged' });
    // @ts-expect-error required FormRequest fields remain required
    void save({});
    return { date, title, saved };
}

function immutableRows(rows: InferLiveQueryResult<typeof index.tasks>) {
    // @ts-expect-error generated rows are readonly
    rows[0]!.title = 'Changed';
}

function useStandaloneContracts(zero: Zero<typeof schema, undefined, Context>) {
    const input: InferQueryInput<typeof search> = { input: { title: 'Hello' } };
    const rows: Promise<InferQueryResult<typeof search>> = zero.run(search(input), { type: 'complete' });
    const view = zero.materialize(detail({ id: 'a' }));
    const preload = zero.preload(search(input));
    const live = useData(search.live(input), { enabled: false });
    const decoded: InferLiveQueryResult<typeof search> = live.data;
    const date: Date | null | undefined = decoded[0]?.completed_at;
    // @ts-expect-error standalone queries have no route selectors
    search({ input: {}, __route: 'forged' });
    // @ts-expect-error scalar query arguments retain their declared types
    detail({ id: 42 });
    // @ts-expect-error trusted identity is not a query input
    search({ input: { title: 'Hello', user_id: 'bob' } });
    return { rows, view, preload, date };
}

function formContract(runtime: () => ControllerFormRuntime<Schema, ClientContext>) {
    const definition = defineForm<{ title: string }>()({
        id: 'Task',
        defaultValues: { title: '' },
        fields: {},
        groups: {},
    });
    const form = createControllerForm(store, definition, runtime);
    const wrong = defineForm<{ title: number }>()({
        id: 'Wrong',
        defaultValues: { title: 42 },
        fields: {},
        groups: {},
    });
    // @ts-expect-error a form must decode into the controller's input contract
    createControllerForm(store, wrong, runtime);
    // @ts-expect-error the descriptor retains the controller's required input fields
    form.submission.submit({}, { signal: new AbortController().signal, onProgress: () => {} });
    return form;
}

function editableInputs(result: QueryData<typeof choices.tasks>) {
    result.inputs.set({ status: 'completed', priority: 7, direction: 'desc' });
    // @ts-expect-error options are finite unions inferred from the FormRequest
    result.inputs.set({ status: 'unknown' });
    // @ts-expect-error route/model identity is not an editable query input
    result.inputs.set({ task: 'other' });
    const options: readonly ['active', 'completed'] = result.inputs.options.status;
    return options;
}

function nativeAndDecoded(wire: InferQueryResult<typeof index.tasks>) {
    const timestamp: number | null | undefined = wire[0]?.created_at;
    const input: InferQueryInput<typeof dated.tasks> = { input: { after: new Date() } };
    dated.tasks(input);
    // @ts-expect-error public date inputs must be Dates, not wire timestamps
    dated.tasks({ input: { after: 1700000000000 } });
    return timestamp;
}

function generatedPageTypes(page: { heading: string; tasks: QueryData<typeof index.tasks> }) {
    const title: string = page.tasks.data[0]!.title;
    const heading: string = page.heading;
    // @ts-expect-error consumer-owned props remain typed without a global namespace
    const missing = page.unknown;
    return { title, heading };
}

function useTableContracts() {
    const table = useDataTable(tasks, {
        columns: [column.text('title', { editor: editors.text(), query: { filter: 'title', sort: 'title' } })],
    });
    // @ts-expect-error generated model fields constrain editable columns
    useDataTable(tasks, { columns: [column.text('unknown')] });
    // @ts-expect-error column query associations cannot invent backend capabilities
    useDataTable(tasks, { columns: [column.text('title', { query: { sort: 'done' } })] });
    useDataTable(tasks, { urlState: true });
    useDataTable(tasks, { urlState: { key: 'secondary', history: 'push' }, initialState: { groupBy: [] } });
    // @ts-expect-error URL state and application-controlled state cannot own the same session
    useDataTable(tasks, { urlState: true, state: table.state, onStateChange: () => {} });
    table.setFilter({ kind: 'condition', field: 'title', operator: 'contains', value: 'alpha' });
    table.setFilter({ kind: 'condition', field: 'completed', operator: 'equals', value: true });
    // @ts-expect-error generated aliases, not database column names
    table.setFilter({ kind: 'condition', field: 'done', operator: 'equals', value: true });
    // @ts-expect-error booleans are not strings
    table.setFilter({ kind: 'condition', field: 'completed', operator: 'equals', value: 'true' });
    // @ts-expect-error text operators are capabilities, not arbitrary strings
    table.setFilter({ kind: 'condition', field: 'title', operator: 'endsWith', value: 'x' });
    // @ts-expect-error only admitted sorts
    table.setSort([{ field: 'done', direction: 'asc' }]);
    // @ts-expect-error only admitted group dimensions
    table.setGroupBy([{ field: 'user_id', direction: 'asc' }]);
    table.setGroupBy([
        { field: 'list', direction: 'desc' },
        { field: 'done', direction: 'asc' },
    ]);
    // @ts-expect-error legacy string grouping is no longer supported
    table.setGroupBy(['done']);
    // @ts-expect-error every grouping rule requires a direction
    table.setGroupBy([{ field: 'done' }]);
    // @ts-expect-error only ascending or descending group directories
    table.setGroupBy([{ field: 'done', direction: 'sideways' }]);
    const item = table.items[0];
    if (item?.kind === 'row') {
        const title: string = item.row.title;
        const completed: boolean = item.row.done;
        return { title, completed };
    }
    return undefined;
}

function typedCellChanges(change: CellChange<{ title: string; done: boolean }>) {
    if (change.field === 'done') {
        const value: boolean = change.value;
        // @ts-expect-error the field discriminant narrows its value
        const text: string = change.value;
        return value;
    }
    return change.value.toUpperCase();
}
