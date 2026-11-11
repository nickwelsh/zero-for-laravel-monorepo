import { expect, it } from 'vite-plus/test';
import tasks from '../../../tests/interop/table.generated';
import { groupId, readTableState, writeTableState, type TableState } from './index';

const { capabilities } = tasks;
const defaults = {
    filter: null,
    sort: [{ field: 'title', direction: 'asc' }],
    groupBy: ['done', 'list'],
    expanded: [],
};

it('round-trips the full view through a shareable URL without changing other parameters or tables', () => {
    const state: TableState<typeof capabilities> = {
        filter: {
            kind: 'and',
            children: [
                { kind: 'condition', field: 'title', operator: 'contains', value: 'R&D + café / 東京 #1' },
                { kind: 'condition', field: 'completed', operator: 'equals', value: false },
            ],
        },
        sort: [{ field: 'title', direction: 'desc' }],
        groupBy: ['done', 'list'],
        expanded: [
            groupId([{ field: 'done', value: false }]),
            groupId([
                { field: 'done', value: false },
                { field: 'list', value: null },
            ]),
        ],
    };
    const original = new URL('https://example.com/tasks?tag=a&tag=b&table.other=untouched#results');
    const encoded = writeTableState(capabilities, original.searchParams, state);
    expect(original.searchParams.has('table.tasks')).toBe(false);
    const shared = new URL(original);
    shared.search = encoded.toString();
    expect(readTableState(capabilities, new URL(shared.href).searchParams)).toEqual(state);
    expect(shared.searchParams.getAll('tag')).toEqual(['a', 'b']);
    expect(shared.searchParams.get('table.other')).toBe('untouched');
    expect(shared.hash).toBe('#results');

    const cleared = { filter: null, sort: [], groupBy: [], expanded: [] };
    expect(readTableState(capabilities, writeTableState(capabilities, encoded, cleared))).toEqual(cleared);
});

it('uses descriptor defaults for missing, malformed, unsupported or stale URL state', () => {
    expect(readTableState(capabilities, new URLSearchParams())).toEqual(defaults);
    for (const value of [
        'not json',
        'null',
        JSON.stringify({ version: 2, state: defaults }),
        JSON.stringify({ version: 1, state: {} }),
        JSON.stringify({ version: 1, state: { ...defaults, expanded: [123] } }),
        JSON.stringify({ version: 1, state: { ...defaults, sort: [{ field: 'deleted', direction: 'asc' }] } }),
        JSON.stringify({ version: 1, state: { ...defaults, groupBy: ['removed'] } }),
        JSON.stringify({
            version: 1,
            state: {
                ...defaults,
                filter: { kind: 'condition', field: 'title', operator: 'equals', value: 'unsupported' },
            },
        }),
    ]) {
        expect(readTableState(capabilities, new URLSearchParams({ 'table.tasks': value }))).toEqual(defaults);
    }
    expect(() =>
        writeTableState(capabilities, new URLSearchParams(), {
            ...defaults,
            sort: [{ field: 'title', direction: 'sideways' }],
        } as unknown as TableState<typeof capabilities>),
    ).toThrow('Invalid table state');
});
