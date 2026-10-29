import { describe, expect, it } from 'vite-plus/test';
import { Task, definitions, schema } from '../../../tests/interop/generated';
import { createZeroForLaravel } from './index';

describe('generated models', () => {
    it('accepts numeric record IDs for reads and updates on existing integer-key tables', () => {
        const runtime = createZeroForLaravel(schema, {
            ...definitions,
            Task: {
                ...definitions.Task,
                mutations: ['update'],
                idGenerator: null,
                columns: {
                    ...definitions.Task.columns,
                    id: { ...definitions.Task.columns.id, type: 'number' },
                },
            },
        } as const);
        expect(runtime.models.Task.query().one(42).args.id).toBe(42);
        expect(runtime.models.Task.update().prepare({ id: 42, title: 'Updated' }).args.id).toBe(42);
        expect(() =>
            runtime.models.Task.update().prepare({
                id: '42',
                title: 'Updated',
            } as never),
        ).toThrow(/value/i);
    });
    it('rejects null substring filters even when the column is nullable', () => {
        const runtime = createZeroForLaravel(schema, {
            ...definitions,
            Task: {
                ...definitions.Task,
                columns: {
                    ...definitions.Task.columns,
                    title: { ...definitions.Task.columns.title, nullable: true },
                },
            },
        } as const);
        expect(() =>
            runtime.models.Task.query()
                .all()
                .filter({ model: 'Task', name: 'title', value: null } as never),
        ).toThrow(/string/i);
    });
    it('tracks nested include shapes and refinements', () => {
        const selection = Task.query()
            .all()
            .with('list', (list) => list.with('tasks', 2));
        expect(selection.args.include).toEqual({
            list: { include: { tasks: { limit: 2 } } },
        });
        const rows = selection.decode([
            {
                id: 'a',
                list: { id: 'l', tasks: [{ id: 'b', created_at: 1700000000000 }] },
            },
        ]);
        expect(rows[0]?.data.list?.data.tasks[0]?.data.created_at).toEqual(new Date(1700000000000));
    });
    it('validates portable FormRequest rules before enqueueing', () => {
        const runtime = createZeroForLaravel(schema, {
            ...definitions,
            Task: {
                ...definitions.Task,
                validation: {
                    create: {
                        title: ['required', 'max:3'],
                        done: ['required', 'boolean'],
                    },
                },
            },
        } as const);
        expect(() => runtime.models.Task.create().prepare({ title: 'long', done: false })).toThrow(/max:3/);
        expect(() => runtime.models.Task.create().prepare({ title: 'ok' } as never)).toThrow(/required/);
        expect(runtime.models.Task.create().prepare({ title: 'ok', done: false }).args.data.done).toBe(false);
    });
    it('builds immutable query arguments and uses native named queries', () => {
        const base = Task.query().all();
        const refined = base
            .filter({ model: 'Task', name: 'done', value: false })
            .with('list')
            .orderBy('title')
            .limit(20);
        expect(base.args).toEqual({});
        expect(refined.args).toEqual({
            filters: { done: false },
            include: { list: {} },
            order: [['title', 'asc']],
            limit: 20,
        });
        expect(refined.request().query.queryName).toBe('Task.all');
    });

    it('keeps null, false, empty strings, and unset distinct', () => {
        const selection = Task.query()
            .all()
            .filter({ model: 'Task', name: 'done', value: false })
            .filter({ model: 'Task', name: 'title', value: '' })
            .filter({ model: 'Task', name: 'completed_at', value: null });
        expect(selection.args.filters).toEqual({
            done: false,
            title: '',
            completed_at: null,
        });
        expect(selection.filter({ model: 'Task', name: 'title', value: undefined }).args.filters).toEqual({
            done: false,
            completed_at: null,
        });
    });

    it('decodes dates in roots and included rows without changing native rows', () => {
        const row = {
            id: 'a',
            title: 'Hello',
            created_at: 1700000000000,
            completed_at: null,
            list: { id: 'l', name: 'List' },
        };
        const decoded = Task.query().all().with('list').decode([row]);
        expect(decoded[0]?.data.created_at).toEqual(new Date(1700000000000));
        expect(decoded[0]?.data.completed_at).toBeNull();
        expect(row.created_at).toBe(1700000000000);
    });

    it('prepares IDs and timestamps once before enqueueing and hides protected fields', () => {
        let ticks = 0;
        const runtime = createZeroForLaravel(schema, definitions, {
            now: () => new Date(++ticks),
            id: () => '019a0000-0000-7000-8000-000000000001',
        });
        const operation = runtime.models.Task.create();
        const request = operation.prepare({
            title: 'Hello',
            completed_at: new Date(1700000000123),
        });
        expect(request.args).toEqual({
            id: '019a0000-0000-7000-8000-000000000001',
            data: { title: 'Hello', completed_at: 1700000000123 },
            now: 1,
        });
        expect(ticks).toBe(1);
        expect(() => operation.prepare({ title: 'Hello', user_id: 'bob' } as never)).toThrow(/protected|Unknown/);
        expect(() => operation.prepare({} as never)).toThrow(/required/);
    });

    it('hydrates collision-safe snapshots and binds destructured mutations to their ID', () => {
        const runtime = createZeroForLaravel(schema, definitions, { now: () => new Date(123) });
        const row = runtime.models.Task.query()
            .all()
            .with('list')
            .decode([
                {
                    id: 'a',
                    title: 'Original',
                    done: false,
                    completed_at: null,
                    created_at: 1700000000000,
                    list: { id: 'l', name: 'List' },
                },
            ])[0]!;
        const { update, destroy, data, list } = row;
        expect(data.list?.data.name).toBe('List');
        expect(data.created_at).toBeInstanceOf(Date);
        expect(update().defaultValues).toEqual({ title: 'Original', done: false, completed_at: null });
        expect(update().prepare({ title: 'Changed' }).args).toEqual({ id: 'a', data: { title: 'Changed' }, now: 123 });
        expect(destroy().prepare({}).args.id).toBe('a');
        expect(() => update().prepare({ id: 'forged', title: 'Changed' } as never)).toThrow(/protected/);
        expect(list.request().args).toEqual({ id: 'a', include: { list: {} } });
        expect(list.decode({ id: 'a', list: { id: 'l', name: 'Lazy' } })?.data.name).toBe('Lazy');
        expect(Object.isFrozen(row)).toBe(true);
        expect(Object.isFrozen(data)).toBe(true);
    });

    it('keeps column names separate from record methods and clones JSON values', () => {
        const runtime = createZeroForLaravel(schema, {
            ...definitions,
            Task: {
                ...definitions.Task,
                columns: {
                    ...definitions.Task.columns,
                    update: { ...definitions.Task.columns.title },
                    preferences: { ...definitions.Task.columns.title, type: 'json' },
                },
            },
        } as const);
        const raw = { id: 'a', update: 'column', preferences: { theme: 'dark' } };
        const row = runtime.models.Task.query().all().decode([raw])[0]!;
        expect(row.data.update).toBe('column');
        expect(typeof row.update).toBe('function');
        expect(row.data.preferences).not.toBe(raw.preferences);
    });

    it('rejects runtime calls that bypass generated TypeScript restrictions', () => {
        expect(() =>
            Task.query()
                .all()
                .with('list', 2 as never),
        ).toThrow(/limit/i);
        expect(() =>
            Task.query()
                .all()
                .orderBy('secret' as never),
        ).toThrow(/sort/i);
        expect(() => Task.query().all().limit(1001)).toThrow(/limit/i);
        expect(() =>
            Task.query()
                .all()
                .filter({ model: 'Other', name: 'title', value: 'x' } as never),
        ).toThrow(/model/i);
    });
});

describe('cast hydration', () => {
    it('hydrates supported primitive and JSON casts and serializes public mutation values', () => {
        const text = definitions.Task.columns.title;
        const runtime = createZeroForLaravel(schema, {
            ...definitions,
            Task: {
                ...definitions.Task,
                writable: ['title', 'preferences', 'count', 'flag', 'status'],
                validation: { update: { flag: ['boolean'], preferences: ['max:2'] } },
                columns: {
                    ...definitions.Task.columns,
                    preferences: { ...text, nullable: true, cast: 'array' },
                    count: { ...text, cast: 'integer' },
                    flag: { ...text, cast: 'boolean' },
                    status: { ...text, enum: ['draft', 'published'] },
                },
            },
        } as const);
        const raw = {
            id: 'a',
            title: 'Title',
            preferences: '{"theme":"dark"}',
            count: '42',
            flag: '0',
            status: 'draft',
        };
        const row = runtime.models.Task.query().all().decode([raw])[0]!;
        expect(row.data.preferences).toEqual({ theme: 'dark' });
        expect(row.data.count).toBe(42);
        expect(row.data.flag).toBe(false);
        const request = row
            .update()
            .prepare({ preferences: { theme: 'light' }, count: 12, flag: true, status: 'published' });
        expect(request.args.data).toEqual({
            preferences: '{"theme":"light"}',
            count: '12',
            flag: '1',
            status: 'published',
        });
        expect(() => row.update().prepare({ status: 'invalid' } as never)).toThrow(/value/);
        expect(() => row.update().prepare({ count: 1.5 })).toThrow(/cast/);
        expect(raw.preferences).toBe('{"theme":"dark"}');
    });
});

it('filter shorthand shares conversion, removal and validation with filter objects', () => {
    expect(Task.query.all().filter('done', false).args).toEqual(
        Task.query.all().filter({ model: 'Task', name: 'done', value: false }).args,
    );
    expect(Task.query.all().filter('done', false).filter('done', undefined).args.filters).toEqual({});
    expect(Task.query.all().filter('completed_at', null).args.filters).toEqual({ completed_at: null });
    expect(Task.query.all().filter('title_operator', { operator: '!=', value: 'hidden' }).args.filters).toEqual({
        title_operator: { operator: '!=', value: 'hidden' },
    });
    expect(() => Task.query.all().filter('done', 'false' as never)).toThrow();
});

describe('bounded declared filters', () => {
    const runtime = createZeroForLaravel(schema, {
        ...definitions,
        Task: {
            ...definitions.Task,
            filters: {
                ...definitions.Task.filters,
                ids: { kind: 'in', field: 'id', maxItems: 2 },
                list_name: { kind: 'related', field: 'list', operator: 'id' },
                rules: {
                    kind: 'conditions',
                    field: '',
                    maxItems: 2,
                    conditions: {
                        done: { kind: 'exact', field: 'done' },
                        list: { kind: 'related', field: 'list', operator: 'id' },
                    },
                },
            },
        },
    } as const);
    it('builds sets and related groups through the native generated query', () => {
        const selection = runtime.models.Task.query
            .all()
            .filter('ids', ['a', 'b'])
            .filter('rules', {
                match: 'any',
                rules: [
                    { field: 'done', value: false },
                    { field: 'list', value: 'owned' },
                ],
            });
        expect(selection.args.filters?.ids).toEqual(['a', 'b']);
        expect(() => selection.request()).not.toThrow();
        expect(() => runtime.models.Task.query.all().filter('ids', ['a', 'b', 'c']).request()).toThrow(/bounded/);
        expect(() =>
            runtime.models.Task.query
                .all()
                .filter('rules', { match: 'all', rules: [{ field: 'secret', value: 'x' }] } as never)
                .request(),
        ).toThrow(/Unknown/);
    });
});
