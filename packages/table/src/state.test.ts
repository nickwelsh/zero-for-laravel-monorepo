import { expect, it } from 'vite-plus/test';
import { execFileSync } from 'node:child_process';
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
    encoded.append('table.tasks[x]', 'invalid');
    encoded.append('table.tasks[y]', 'invalid');
    expect(readTableState(capabilities, writeTableState(capabilities, encoded, state))).toEqual(state);
});

it('agrees with PHP on normalization, rejection, and bidirectional URL round trips', () => {
    const initialState = { groupBy: [] };
    const full = {
        ...defaults,
        filter: {
            kind: 'or',
            children: [
                { kind: 'condition', field: 'title', operator: 'contains', value: 'R&D + café / 東京 #1' },
                {
                    kind: 'and',
                    children: [{ kind: 'condition', field: 'completed', operator: 'equals', value: false }],
                },
            ],
        },
        expanded: [groupId([{ field: 'done', value: false }])],
    };
    const envelope = (state: unknown) => JSON.stringify({ version: 1, state });
    let deep: unknown = { kind: 'condition', field: 'completed', operator: 'equals', value: true };
    for (let index = 0; index < 8; index++) deep = { kind: 'and', children: [deep] };
    const inputs = [
        envelope(full),
        envelope({ filter: null, sort: [], groupBy: [] }),
        envelope({ sort: [] }),
        envelope({}),
        'null',
        'not-json',
        envelope({ filter: deep }),
        envelope({
            filter: {
                kind: 'or',
                children: Array.from({ length: 100 }, () => ({
                    kind: 'condition',
                    field: 'completed',
                    operator: 'equals',
                    value: true,
                })),
            },
        }),
        envelope({ ...full, expanded: Array.from({ length: 101 }, () => 'g:[]') }),
        envelope({ expanded: ['é'.repeat(1025)] }),
        envelope({ filter: { kind: 'condition', field: 'title', operator: 'contains', value: 'é'.repeat(1001) } }),
        envelope({ filter: { kind: 'condition', field: 'completed', operator: 'equals', value: 'false' } }),
        envelope({
            ...full,
            filter: { kind: 'condition', field: 'title', operator: 'contains', value: 'ok', secret: true },
        }),
        envelope({ sort: [{ field: 'title', direction: 'desc', secret: true }] }),
        envelope({ tenant: 'another' }),
        envelope({ sort: null }),
        envelope([]),
        JSON.stringify({ version: 2, state: full }),
        ' '.repeat(16385),
    ];
    const cases = inputs.map((input) => ({
        key: 'table.people',
        defaults: initialState,
        query: new URLSearchParams({ 'table.people': input, other: 'keep' }).toString(),
    }));
    cases.push({
        key: 'table.people',
        defaults: initialState,
        query: cases[0]!.query + '&table.people=' + encodeURIComponent(envelope({ sort: [] })),
    });
    cases.push({ key: 'table.people', defaults: initialState, query: cases[0]!.query + '&table.people[x]=1' });
    cases.push({ key: 'table.people', defaults: initialState, query: '' });
    const php = JSON.parse(
        execFileSync('php', ['packages/laravel/tests/Fixtures/export-controller-mutations.php', 'table-url'], {
            input: JSON.stringify(cases),
            encoding: 'utf8',
        }),
    ) as { state: TableState<typeof capabilities>; encoded: string }[];
    cases.forEach((entry, index) => {
        const options = { key: entry.key, initialState };
        const state = readTableState(capabilities, new URLSearchParams(entry.query), options);
        expect(state, `case ${index}`).toEqual(php[index]!.state);
        expect(
            readTableState(capabilities, new URLSearchParams({ [entry.key]: php[index]!.encoded }), options),
        ).toEqual(state);
    });
    expect(php[0]!.state).toEqual(full);
    expect(php[1]!.state).toEqual({ filter: null, sort: [], groupBy: [], expanded: [] });
    expect(php[2]!.state).toEqual({ ...defaults, groupBy: [], sort: [] });
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
