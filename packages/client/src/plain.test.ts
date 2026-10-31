import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { expect, it } from 'vite-plus/test';
import { liveQuery } from './index';
import { schema, definitions } from '../../../tests/interop/generated';

const queries = defineQueries({
    tasks: defineQuery(({ args: _args }: { args: Record<string, never> }) =>
        createBuilder(schema).tasks.related('list').related('comments'),
    ),
});

it('decodes controller results as immutable plain rows with nested dates and scalar JSON intact', () => {
    const selection = liveQuery(queries.tasks, {}, definitions, 'Task', false);
    const raw = [
        {
            id: 't',
            completed_at: 1700000000123,
            list: { id: 'l', name: 'List' },
            comments: [{ id: 'c', body: 'Child' }],
        },
    ];
    const rows = selection.decode(raw);
    expect(rows[0]?.list?.name).toBe('List');
    expect(rows[0]?.comments[0]?.body).toBe('Child');
    expect(rows[0]?.completed_at?.toISOString()).toBe('2023-11-14T22:13:20.123Z');
    expect(Object.isFrozen(rows[0]?.comments)).toBe(true);
    expect(selection.decode(raw)).toBe(rows);
    const absent = selection.decode([{ id: 't', completed_at: null }]);
    expect(absent[0]?.completed_at).toBeNull();
    expect(absent[0]).not.toHaveProperty('list');
    const json = { data: { data: 'scalar JSON' } };
    const scalar = liveQuery(
        queries.tasks,
        {},
        {
            ...definitions,
            Task: {
                ...definitions.Task,
                columns: {
                    ...definitions.Task.columns,
                    title: { ...definitions.Task.columns.title, type: 'json', cast: 'object' },
                },
            },
        } as const,
        'Task',
        false,
    );
    const scalarRows = scalar.decode([{ id: 't', title: json }]);
    expect(scalarRows[0]?.title).toEqual(json);
    expect(scalarRows[0]?.title).not.toBe(json);
});
