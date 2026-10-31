import { useData, type QueryData } from '@nickwelsh/zero-for-laravel/react';
import type {
    InferLiveQueryResult,
    InferQueryInput,
    InferQueryResult,
    InferMutationInput,
} from '@nickwelsh/zero-for-laravel';
import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import type { choices } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { index, show, dated } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { store } from './controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';

// Compiled by typecheck; these exercise only public generated controller contracts.
function useContracts() {
    const result = useData(index.tasks.live({ title: 'Hello' }));
    const rows: InferLiveQueryResult<typeof index.tasks> = result.data;
    const date: Date | null | undefined = rows[0]?.completed_at;
    const detail = useData(show.task.live({ task: 'id' }));
    const title: string | undefined = detail.data?.title;
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
