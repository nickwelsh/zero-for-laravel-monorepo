import { Task, TaskList, schema, definitions } from './generated';
import { nestedRuntime, nestedDefinitions } from './nested';
import { createZeroForLaravel } from '../../packages/client/src/runtime';
import { toPlain } from '../../packages/client/src/record';
import { createDomainMutations } from '../../packages/client/src/domain';
import type { SelectionData } from '../../packages/client/src/query';
import { useData, type Data } from '@nickwelsh/zero-for-laravel/react';
import type { InferLiveQueryResult } from '@nickwelsh/zero-for-laravel';
import type { index } from './controller-api/ZeroForLaravel/Tests/Fixtures/QueryController';
import { useMutation } from '../../packages/client/src/react/mutation';
import {
    useFilter,
    useFilters,
    type FilterControls,
    type FilterControlProps,
} from '../../packages/client/src/react/filters';
import type { ComponentType } from 'react';
import { createFormbraid } from '@formbraid/react';

const formbraid = createFormbraid({ fieldComponents: {}, formComponents: {} });

// Compiled by typecheck; these are public-contract checks, not runtime tests.
const fixtureDate = new Date('2026-01-01T00:00:00Z');

function useContracts() {
    const selection = Task.query().all().with('list');
    const data = useData(selection);
    const selected: SelectionData<typeof selection> = data.data;
    const date: Date | null | undefined = data.data[0]?.data.completed_at;
    const name: string | undefined = data.data[0]?.data.list?.data.name;
    const one = useData(Task.query().one('id').with('list'));
    const title: string | undefined = one.data?.data.title;
    const mutation = useMutation(Task.create());
    mutation({ title: 'Hello', completed_at: fixtureDate });
    const BooleanControl: ComponentType<FilterControlProps<boolean>> = () => null;
    const SharedBooleanControl: ComponentType<FilterControlProps<boolean | null>> = () => null;
    const controls: FilterControls = { boolean: SharedBooleanControl };
    useFilter(Task, 'done', { component: BooleanControl });
    Task.query()
        .all()
        .filters([
            { model: 'Task', name: 'done', value: false },
            { model: 'Task', name: 'title', value: 'Hello' },
        ]);
    const [filters] = useFilters(Task, ['done', 'title']);
    Task.query().all().filters(filters);
    Task.query()
        .all()
        .filters([
            // @ts-expect-error filter names and values stay correlated in filter arrays
            { model: 'Task', name: 'done', value: 'false' },
        ]);
    // @ts-expect-error wrong custom control value contract
    useFilter(Task, 'title', { component: BooleanControl });
    // @ts-expect-error only declared filters are available
    useFilter(Task, 'secret');
    // @ts-expect-error singular queries cannot paginate
    Task.query().one('id').infinite();
    // @ts-expect-error to-one includes cannot have a limit
    Task.query().all().with('list', 5);
    // @ts-expect-error junction includes cannot have a limit
    Task.query().all().with('tags', 5);
    Task.query().all().with('comments', 5);
    const nested = useData(
        Task.query()
            .all()
            .with('list', (list) => list.with('tasks', 2)),
    );
    const nestedDate: Date | null | undefined = nested.data[0]?.data.list?.data.tasks[0]?.data.created_at;
    Task.query()
        .all()
        .with('list', (list) => {
            // @ts-expect-error nested to-one includes cannot have limits either
            return list.limit(2);
        });
    Task.query()
        .all()
        .with('list', (list) => {
            // @ts-expect-error nested includes remain opt-in
            return list.with('user');
        });
    // @ts-expect-error queries are opt-in
    TaskList.query().all();
    // @ts-expect-error mutations are opt-in
    TaskList.create();
    // @ts-expect-error undeclared relations cannot be included
    Task.query().all().with('user');
    // @ts-expect-error server-filled ownership cannot be supplied
    Task.create().prepare({ title: 'Hello', user_id: 'alice' });
    // @ts-expect-error Date input cannot be supplied as a wire number
    Task.create().prepare({ title: 'Hello', completed_at: 1700000000 });
    // @ts-expect-error create requires title
    Task.create().prepare({});
    // @ts-expect-error hidden columns are absent from public rows
    data.data[0]?.secret;
    // @ts-expect-error undeclared sorts are absent
    Task.query().all().orderBy('secret');
    // @ts-expect-error boolean filters do not accept string values
    Task.query().all().filter({ model: 'Task', name: 'done', value: 'false' });
    return { date, name, title };
}

function useBoundContracts() {
    const task = useData(Task.query().all().with('list')).data[0]!;
    const update = useMutation(task.update());
    update({ completed_at: fixtureDate });
    update.form.setValue('title', 'Changed');
    const defaults: string | undefined = update.defaultValues.title;
    const list = useData(task.list);
    const name: string | undefined = list.data?.data.name;
    useMutation(task.destroy()).submit();
    // @ts-expect-error bound operations cannot change their record identity
    update({ id: 'forged' });
    // @ts-expect-error relations that were not included are not present in data
    task.data.tags;
    // @ts-expect-error columns live under data
    task.title;
    return { defaults, name };
}

declare module '@nickwelsh/zero-for-laravel' {
    interface ModelTypes {
        Task: { preferences: { theme: 'light' | 'dark' } };
    }
}

function useDtoContracts() {
    const runtime = createZeroForLaravel(schema, {
        ...definitions,
        Task: {
            ...definitions.Task,
            writable: ['title', 'preferences'],
            columns: { ...definitions.Task.columns, preferences: { ...definitions.Task.columns.title, type: 'json' } },
        },
    } as const);
    const task = useData(runtime.models.Task.query.all()).data[0]!;
    const theme: 'light' | 'dark' = task.data.preferences.theme;
    task.update().prepare({ preferences: { theme: 'dark' } });
    // @ts-expect-error DTO field types refine mutation inputs as well as result data
    task.update().prepare({ preferences: { theme: 'unknown' } });
    Task.query.all().filter({ model: 'Task', name: 'completed_after', value: fixtureDate });
    Task.query.all().filter({ model: 'Task', name: 'title_operator', value: { operator: '!=', value: 'hidden' } });
    // @ts-expect-error dynamic operators retain the column's value type
    Task.query.all().filter({ model: 'Task', name: 'title_operator', value: { operator: '!=', value: 1 } });
    return theme;
}

function useNestedContracts() {
    const { models } = nestedRuntime();
    const operation = models.Task.create().with('list').with('comments');
    const mutation = useMutation(operation);
    const instance = formbraid.useForm(mutation.form);
    instance.form.setFieldValue('list.name', 'List');
    instance.form.setFieldValue('comments.0.body', 'Comment');
    instance.form.setFieldValue('completed_at', '2026-10-19T12:00:00Z');
    const component: 'TextField' = mutation.form.definition.fields['list.name'].component;
    // @ts-expect-error Formbraid draft dates are strings, with native Dates restored on submission.
    instance.form.setFieldValue('completed_at', fixtureDate);
    // @ts-expect-error Formbraid retains nested writable value types.
    instance.form.setFieldValue('comments.0.body', false);
    // @ts-expect-error Generated form fields omit trusted linking keys.
    mutation.form.definition.fields['comments.*.task_id'];
    // @ts-expect-error Selected to-one relations expose their prefixed fields rather than a container field.
    mutation.form.definition.fields.list;
    // @ts-expect-error Generated IDs are not editable fields on create.
    mutation.form.definition.fields.id;
    mutation({ title: 'Root', list: { name: 'List' }, comments: [{ body: 'Comment' }] });
    mutation.form.setValue('list.name', 'List');
    mutation.form.setValue('comments.0.body', 'Comment');
    // @ts-expect-error child field values retain their model types
    mutation.form.setValue('comments.0.body', false);
    // @ts-expect-error nested fields are restricted to declared child inputs
    mutation.form.setValue('comments.0.secret', 'hidden');
    // @ts-expect-error child linking keys are derived by the executor
    operation.prepare({ title: 'Root', comments: [{ body: 'Comment', task_id: 'forged' }] });
    // @ts-expect-error a selected belongsTo relation owns its foreign key
    operation.prepare({ title: 'Root', list_id: 'forged' });
    // @ts-expect-error nested create requires the child's required fields
    operation.prepare({ title: 'Root', comments: [{}] });
    // @ts-expect-error selecting a nested operation does not expose it standalone
    models.TaskList.create();
    // @ts-expect-error allowed includes do not grant nested mutation access
    models.Task.create().with('tags');
    // @ts-expect-error update is not enabled on comments inside a root create
    models.Task.create().with('comments', 'update');
    models.Task.update()
        .with('comments', 'create')
        .prepare({ id: 'a', comments: [{ body: 'New' }] });
    models.Task.update()
        .with('comments')
        .prepare({ id: 'a', comments: [{ id: 'c', body: 'Changed' }] });
    models.Task.create()
        .with('list', 'create', (list) => list.with('tasks'))
        .prepare({ title: 'Root', list: { name: 'List', tasks: [{ title: 'Child' }] } });
}

function requiredNestedLink() {
    const runtime = createZeroForLaravel(schema, {
        ...nestedDefinitions,
        Task: {
            ...nestedDefinitions.Task,
            columns: {
                ...nestedDefinitions.Task.columns,
                list_id: { ...nestedDefinitions.Task.columns.list_id, nullable: false },
            },
        },
    } as const);
    runtime.models.Task.create()
        .with('list')
        .prepare({ title: 'Root', list: { name: 'Required' } });
    // @ts-expect-error nested input replaces the required foreign key
    runtime.models.Task.create().with('list').prepare({ title: 'Missing list' });
}

// Selection-local filter names and values come from the generated contract.
Task.query.all().filter('done', false).filter('completed_at', null).filter('title', undefined);
// @ts-expect-error unknown filter
Task.query.all().filter('private_secret', true);
// @ts-expect-error boolean filter requires a boolean
Task.query.all().filter('done', 'false');
// @ts-expect-error operator values retain the field's type
Task.query.all().filter('title_operator', { operator: '!=', value: 123 });

function plainProjection() {
    const rows = nestedRuntime().models.Task.query.all().with('list').with('comments').decode([]);
    const plain = toPlain(rows);
    const title: string | undefined = plain[0]?.title;
    const list: string | undefined = plain[0]?.list?.name;
    const child: string | undefined = plain[0]?.comments[0]?.body;
    const date: Date | null | undefined = plain[0]?.completed_at;
    // @ts-expect-error plain data has no snapshot wrapper
    plain[0]?.data;
    // @ts-expect-error projections are readonly
    plain[0]!.title = 'Changed';
    return { title, list, child, date };
}

const domain = createDomainMutations(schema, createZeroForLaravel(schema, definitions).mutators, {
    completeTasks: { ids: { type: 'string', array: true, maxItems: 3 }, notify: { type: 'boolean', optional: true } },
} as const);
domain.actions.completeTasks().prepare({ ids: ['id'], notify: false });
// @ts-expect-error domain inputs are generated and typed
domain.actions.completeTasks().prepare({ ids: [123] });
// @ts-expect-error unknown operation
domain.actions.unregistered({});

// A scalar JSON field named data must never be mistaken for a snapshot type.
const scalarProjection = toPlain({ data: { data: 'scalar' } } as const);
const scalarData: 'scalar' = scalarProjection.data.data;

function generatedPageTypes(page: { heading: string; tasks: Data<InferLiveQueryResult<typeof index.tasks>> }) {
    const title: string = page.tasks.data[0]!.title;
    const heading: string = page.heading;
    // @ts-expect-error Consumer-owned props remain typed without a global namespace.
    const missing = page.unknown;
    return { title, heading };
}
