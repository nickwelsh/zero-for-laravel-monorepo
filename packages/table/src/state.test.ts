/* oxlint-disable unicorn/no-array-reverse -- ES2022 support; only newly allocated arrays are reversed. */
import { expect, it } from 'vite-plus/test';
import { execFileSync } from 'node:child_process';
import { createBuilder, Zero, defineMutatorsWithType, defineMutatorWithType } from '@rocicorp/zero';
import { asQueryInternals } from '@rocicorp/zero/bindings';
import { schema } from '../../../tests/interop/generated';
import tasks, { queries } from '../../../tests/interop/table.generated';
import {
    applyTableQuery,
    groupId,
    readTableState,
    writeTableState,
    validateState,
    type TableState,
    type TableQueryInput,
} from './index';

const { capabilities } = tasks;
const tag = {
    model: 'Tag',
    table: 'tags',
    key: 'id',
    keyColumn: capabilities.columns.id,
    cardinality: 'many',
    label: 'Tags',
    operators: ['some', 'has', 'any', 'all'],
    filters: { name: { ...capabilities.filters.title, field: 'name' } },
    relations: {},
    choices: { value: 'id', label: 'name' },
    lookup: { field: 'name', limit: 2 },
} as const;
const report = {
    ...capabilities,
    version: 3,
    relations: { tags: tag },
    displayColumns: [{ field: 'tags', label: 'Tags' }],
} as const;

it('uses full scoped relations for membership and same-record conditions, but bounded scoped rows for display', async () => {
    const mutators = defineMutatorsWithType<typeof schema>()({
        seed: defineMutatorWithType<typeof schema>()(async ({ tx }) => {
            for (const id of ['a', 'b', 'both', 'none']) {
                await tx.mutate.lists.insert({ id, name: id, user_id: 'actor' });
                await tx.mutate.tasks.insert({ id, list_id: id, title: id, user_id: 'actor', done: false });
            }
            for (const id of ['A', 'B', 'hidden'])
                await tx.mutate.tags.insert({ id, name: id, user_id: id === 'hidden' ? 'other' : 'actor' });
            for (const [task_id, tag_id] of [
                ['a', 'A'],
                ['b', 'B'],
                ['both', 'A'],
                ['both', 'B'],
                ['none', 'hidden'],
            ] as const)
                await tx.mutate.task_tag.insert({ task_id, tag_id });
            for (const [id, task_id, body, user_id] of [
                ['4', 'a', 'late match', 'actor'],
                ['1', 'a', 'first', 'actor'],
                ['2', 'a', 'second', 'actor'],
                ['0', 'a', 'secret', 'other'],
                ['3', 'b', 'late', 'actor'],
                ['5', 'b', 'match', 'actor'],
            ] as const)
                await tx.mutate.comments.insert({ id, task_id, body, user_id });
        }),
    });
    const zero = new Zero({
        schema,
        mutators,
        userID: 'table-relations',
        kvStore: 'mem',
        cacheURL: null,
        logLevel: 'error',
    });
    const cap = {
        ...report,
        relations: {
            tags: { ...tag, lookup: null },
            comments: {
                ...tag,
                model: 'Comment',
                table: 'comments',
                choices: null,
                lookup: { field: 'body', limit: 1 },
                filters: { body: { ...capabilities.filters.title, field: 'body' } },
            },
        },
        displayColumns: [{ field: 'comments', label: 'Comments' }],
    } as const;
    try {
        expect((await zero.mutate(mutators.seed()).client).type).toBe('success');
        const run = (filter: TableState<typeof cap>['filter']) =>
            zero.run(
                applyTableQuery(
                    createBuilder(schema).tasks,
                    { filter, groupBy: [], sort: [], path: [], __window: 10 },
                    cap,
                    {
                        tags: (q) => q.where('user_id', '=', 'actor'),
                        comments: (q) => q.where('user_id', '=', 'actor'),
                    },
                ),
            );
        for (const [rule, expected] of [
            [{ operator: 'has', value: 'A' }, ['a', 'both']],
            [{ operator: 'any', value: ['A', 'B'] }, ['a', 'b', 'both']],
            [{ operator: 'all', value: ['A', 'B', 'A'] }, ['both']],
            [{ operator: 'has', value: 'hidden' }, []],
        ] as const)
            expect((await run({ kind: 'relation', field: 'tags', ...rule })).map((row) => row.id)).toEqual(expected);
        const rows = await run({
            kind: 'relation',
            field: 'comments',
            operator: 'some',
            filter: {
                kind: 'and',
                children: [
                    { kind: 'condition', field: 'body', operator: 'contains', value: 'late' },
                    { kind: 'condition', field: 'body', operator: 'contains', value: 'match' },
                ],
            },
        });
        expect(rows.map((row) => row.id)).toEqual(['a']);
        expect(rows[0]).toMatchObject({ comments: [{ id: '1' }, { id: '2' }] });
        const lists = {
            ...report,
            displayColumns: [],
            relations: {
                tasks: {
                    ...tag,
                    table: 'tasks',
                    model: 'Task',
                    lookup: null,
                    choices: null,
                    relations: { tags: { ...tag, lookup: null } },
                },
            },
        } as const;
        const nested = await zero.run(
            applyTableQuery(
                createBuilder(schema).lists,
                {
                    filter: {
                        kind: 'relation',
                        field: 'tasks',
                        operator: 'some',
                        filter: {
                            kind: 'or',
                            children: [
                                { kind: 'relation', field: 'tags', operator: 'has', value: 'A' },
                                { kind: 'relation', field: 'tags', operator: 'has', value: 'hidden' },
                            ],
                        },
                    },
                    groupBy: [],
                    sort: [],
                    path: [],
                    __window: 10,
                },
                lists,
                { tasks: (q) => q.where('id', '!=', 'both'), 'tasks.tags': (q) => q.where('user_id', '=', 'actor') },
            ),
        );
        expect(nested.map((row) => row.id)).toEqual(['a']);
        // @ts-expect-error Absence is not a live table operator, even on an otherwise valid relation.
        expect(() => run({ kind: 'relation', field: 'tags', operator: 'none' })).toThrow('relation operator');
    } finally {
        await zero.close();
    }
});

it('restores only compatible views and validates membership against the declared relation key', () => {
    const state: TableState<typeof report> = {
        filter: { kind: 'relation', field: 'tags', operator: 'all', value: ['saved', 'revoked'] },
        sort: [],
        groupBy: [],
        expanded: [],
    };
    const saved = writeTableState(report, new URLSearchParams(), state);
    expect(readTableState(report, saved)).toEqual(state);
    expect(readTableState({ ...report, stateContract: 'remapped-fields' }, saved).filter).toBeNull();
    expect(readTableState(capabilities, saved).filter).toBeNull();
    const old = writeTableState(capabilities, new URLSearchParams(), {
        ...state,
        filter: { kind: 'condition', field: 'title', operator: 'contains', value: 'Compatible scalar' },
    });
    expect(readTableState(report, old).filter).toBeNull();
    for (const [operator, value] of [
        ['all', []],
        ['any', [5]],
        ['has', ['id']],
        ['none', 'id'],
        ['some', 'id'],
    ]) {
        expect(() =>
            validateState(report, {
                ...state,
                filter: { kind: 'relation', field: 'tags', operator, value },
            } as typeof state),
        ).toThrow('Invalid table state');
    }
});
const defaults = {
    filter: null,
    sort: [{ field: 'title', direction: 'asc' }],
    groupBy: [
        { field: 'done', direction: 'desc' },
        { field: 'list', direction: 'asc' },
    ],
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
        groupBy: [
            { field: 'list', direction: 'desc' },
            { field: 'done', direction: 'asc' },
        ],
        expanded: [
            groupId([{ field: 'list', value: null }]),
            groupId([
                { field: 'list', value: null },
                { field: 'done', value: false },
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
    const envelope = (state: unknown) => JSON.stringify({ version: 2, state });
    let deep: unknown = { kind: 'condition', field: 'completed', operator: 'equals', value: true };
    for (let index = 0; index < 8; index++) deep = { kind: 'and', children: [deep] };
    const inputs = [
        envelope(full),
        envelope({ ...full, groupBy: [...defaults.groupBy].reverse() }),
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
        JSON.stringify({ version: 1, state: full }),
        envelope({ groupBy: ['done', 'list'] }),
        envelope({ groupBy: [{ field: 'done' }] }),
        envelope({ groupBy: [{ field: 'done', direction: 'sideways' }] }),
        envelope({ groupBy: [{ field: 'done', direction: 'asc', extra: true }] }),
        envelope({ groupBy: [{ field: 'removed', direction: 'desc' }] }),
        envelope({
            groupBy: [
                { field: 'done', direction: 'asc' },
                { field: 'done', direction: 'desc' },
            ],
        }),
        envelope({ groupBy: [...defaults.groupBy, { field: 'title', direction: 'asc' }] }),
        envelope({ groupBy: [null] }),
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
    expect(php[1]!.state).toEqual({ ...full, groupBy: [...defaults.groupBy].reverse() });
    expect(php[2]!.state).toEqual({ filter: null, sort: [], groupBy: [], expanded: [] });
    expect(php[3]!.state).toEqual({ ...defaults, groupBy: [], sort: [] });
});

it('uses descriptor defaults for missing, malformed, unsupported or stale URL state', () => {
    expect(readTableState(capabilities, new URLSearchParams())).toEqual(defaults);
    for (const value of [
        'not json',
        'null',
        JSON.stringify({ version: 1, state: defaults }),
        JSON.stringify({ version: 2, state: {} }),
        JSON.stringify({ version: 2, state: { ...defaults, expanded: [123] } }),
        JSON.stringify({ version: 2, state: { ...defaults, sort: [{ field: 'deleted', direction: 'asc' }] } }),
        JSON.stringify({ version: 2, state: { ...defaults, groupBy: ['removed'] } }),
        JSON.stringify({
            version: 2,
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

it('generated live queries validate ordered group paths without adding group ordering to row sorts', () => {
    const query = queries[`q_${capabilities.contract}`];
    const ctx = { user_id: 'alice' };
    const base = createBuilder(schema).tasks.where('deleted_at', 'IS', null);
    for (const groupBy of [capabilities.defaults.groupBy, [...capabilities.defaults.groupBy].reverse()]) {
        const input: TableQueryInput<typeof capabilities> = {
            filter: null,
            sort: [{ field: 'title', direction: 'desc' }],
            groupBy,
            path: groupBy.map(({ field }) => ({ field, value: field === 'done' ? false : null })),
        };
        const ast = (args: TableQueryInput<typeof capabilities>) => {
            const request = query(args);
            return asQueryInternals(request.query.fn({ args: request.args, ctx })).ast;
        };
        const expected = base.where('done', '=', false).where('list_id', 'IS', null);
        expect(ast(input)).toEqual(
            asQueryInternals(expected.orderBy('title', 'desc').orderBy('id', 'asc').limit(3)).ast,
        );
        expect(
            ast({
                ...input,
                groupBy: groupBy.map((rule) => ({ ...rule, direction: rule.direction === 'asc' ? 'desc' : 'asc' })),
            }),
        ).toEqual(ast(input));
        expect(ast({ ...input, sort: [{ field: 'title', direction: 'asc' }] })).toEqual(
            asQueryInternals(expected.orderBy('title', 'asc').orderBy('id', 'asc').limit(3)).ast,
        );
        expect(() => ast({ ...input, path: [...input.path].reverse() })).toThrow('group path');
        expect(() => ast({ ...input, path: input.path.slice(0, 1) })).toThrow('complete leaf path');
    }
    for (const groupBy of [
        ['done'],
        [{ field: 'done' }],
        [{ field: 'done', direction: 'DESC' }],
        [{ field: 'user_id', direction: 'asc' }],
        [{ field: 'done', direction: 'asc', extra: true }],
        [
            { field: 'done', direction: 'asc' },
            { field: 'done', direction: 'desc' },
        ],
        [...capabilities.defaults.groupBy, { field: 'title', direction: 'asc' }],
        [null],
    ]) {
        expect(() =>
            validateState(capabilities, { ...defaults, groupBy } as unknown as TableState<typeof capabilities>),
        ).toThrow('Invalid table state');
    }
});
