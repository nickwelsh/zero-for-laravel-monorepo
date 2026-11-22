import { liveQuery } from '@nickwelsh/zero-for-laravel';
import { createBuilder, defineQueriesWithType, defineQueryWithType } from '@rocicorp/zero';
import { expect, expectTypeOf, it } from 'vite-plus/test';
import {
    definitions,
    schema,
    type CommentRow,
    type Context,
    type TaskListRow,
    type TaskRow,
} from '../../../tests/interop/generated';
import tasks from '../../../tests/interop/table.generated';
import { applyTableQuery, createTable, type TableQueryInput } from './index';

it('exposes declared lookup rows alongside decoded scalar fields', () => {
    const relation = {
        key: 'id',
        keyColumn: definitions.Task.columns.id,
        filters: {},
        operators: [],
        choices: null,
    } as const;
    const capabilities = {
        ...tasks.capabilities,
        version: 3,
        relations: {
            comments: {
                ...relation,
                model: 'Comment',
                table: 'comments',
                cardinality: 'many',
                label: 'Comments',
                lookup: { field: 'body', limit: 3 },
            },
            list: {
                ...relation,
                model: 'TaskList',
                table: 'lists',
                cardinality: 'one',
                label: 'List',
                lookup: { field: 'name', limit: 1 },
            },
            tags: { ...relation, model: 'Tag', table: 'tags', cardinality: 'many', label: 'Tags', lookup: null },
        },
    } as const;
    const zql = createBuilder(schema);
    const defineQuery = defineQueryWithType<typeof schema, Context>();
    const queries = defineQueriesWithType<typeof schema>()({
        rows: defineQuery(({ args }: { args: TableQueryInput<typeof capabilities> }) =>
            applyTableQuery(zql.tasks, args, capabilities),
        ),
    });
    const table = createTable(capabilities, (args) => liveQuery(queries.rows, args, definitions, 'Task', false));
    const selection = table.rows({ filter: null, sort: [], groupBy: [], path: [] });
    const row = selection.decode([
        { title: 'Task', completed_at: 0, comments: [{ body: 'Note' }], list: undefined },
    ])[0]!;

    expectTypeOf(row.comments).toEqualTypeOf<readonly CommentRow[]>();
    expectTypeOf(row.list).toEqualTypeOf<TaskListRow | undefined>();
    expectTypeOf(row).toExtend<TaskRow>();
    expectTypeOf<keyof typeof row>().toEqualTypeOf<keyof TaskRow | 'comments' | 'list'>();
    expect(row.comments[0]?.body).toBe('Note');
    expect(row.list).toBeUndefined();
    expect(row.completed_at).toEqual(new Date(0));
});
