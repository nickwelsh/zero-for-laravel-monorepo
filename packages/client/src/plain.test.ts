import { expect, it } from 'vite-plus/test';
import { nestedRuntime } from '../../../tests/interop/nested';
import { toPlain } from './record';
import { createZeroForLaravel } from './runtime';
import { schema, definitions } from '../../../tests/interop/generated';

it('projects nested snapshots while preserving dates, scalar JSON, and missing relationships', () => {
    const runtime = nestedRuntime();
    const selection = runtime.models.Task.query.all().with('list').with('comments');
    const snapshots = selection.decode([
        {
            id: 't',
            completed_at: 1700000000000,
            list: { id: 'l', name: 'List' },
            comments: [{ id: 'c', body: 'Child' }],
        },
    ]);
    const plain = toPlain(snapshots);
    expect(plain[0]?.list?.name).toBe('List');
    expect(plain[0]?.comments[0]?.body).toBe('Child');
    expect(plain[0]?.completed_at).toBe(snapshots[0]?.data.completed_at);
    expect(plain[0]?.completed_at).toBeInstanceOf(Date);
    expect(Object.isFrozen(plain[0]?.comments)).toBe(true);
    const absent = toPlain(selection.decode([{ id: 't', completed_at: null }]));
    expect(absent[0]?.completed_at).toBeNull();
    expect(absent[0]).not.toHaveProperty('list');
    expect(toPlain(selection.decode([{ id: 't', list: null }]))[0]?.list).toBeUndefined();
    const json = { data: { data: 'scalar JSON' } };
    const scalar = createZeroForLaravel(schema, {
        ...definitions,
        Task: {
            ...definitions.Task,
            columns: {
                ...definitions.Task.columns,
                title: { ...definitions.Task.columns.title, type: 'json', cast: 'object' },
            },
        },
    } as const);
    const scalarRows = scalar.models.Task.query.all().decode([{ id: 't', title: json }]);
    expect(toPlain(scalarRows)[0]?.title).toBe(scalarRows[0]?.data.title);
    expect(toPlain(scalarRows)[0]?.title).toEqual(json);
});
