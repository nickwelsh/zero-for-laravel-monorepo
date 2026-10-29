// @vitest-environment jsdom
import { defineMutatorWithType, defineMutatorsWithType, Zero } from '@rocicorp/zero';
import { describe, expect, it } from 'vite-plus/test';
import { definitions, schema } from '../../../tests/interop/generated';
import { createZeroForLaravel } from './runtime';
import type { ClientContext } from './contract';

const models = {
    ...definitions,
    Tag: { ...definitions.Tag, defaultOrder: [['name', 'asc']] },
    Task: {
        ...definitions.Task,
        filters: {
            ...definitions.Task.filters,
            tag: { kind: 'related', field: 'tags', operator: 'id' },
            rules: {
                kind: 'conditions',
                field: '',
                maxItems: 10,
                conditions: { tag: { kind: 'related', field: 'tags', operator: 'name' } },
            },
        },
    },
} as const;

const runtime = createZeroForLaravel(schema, models);
const authorized = createZeroForLaravel(schema, {
    ...models,
    Task: { ...models.Task, authorizationRelations: ['tags'] },
} as const);

// Seed the actual local store, including rows an authenticated producer could not create.
const seed = defineMutatorsWithType<typeof schema>()({
    seed: defineMutatorWithType<typeof schema, ClientContext>()(async ({ tx }) => {
        for (const [id, title, user, deleted] of [
            ['a', 'Zulu', 'alice', false],
            ['b', 'Alpha', 'alice', false],
            ['c', 'Hidden related owner', 'alice', false],
            ['d', 'Hidden root owner', 'bob', false],
            ['e', 'No tag', 'alice', false],
            ['f', 'Deleted', 'alice', true],
        ] as const) {
            await tx.mutate.tasks.insert({
                id,
                title,
                user_id: user,
                done: false,
                list_id: null,
                completed_at: null,
                created_at: 1,
                updated_at: 1,
                deleted_at: deleted ? 2 : null,
            });
        }
        await tx.mutate.tags.insert({ id: 'owned', name: 'Reading', user_id: 'alice' });
        await tx.mutate.tags.insert({ id: 'other', name: 'Other', user_id: 'alice' });
        await tx.mutate.tags.insert({ id: 'foreign', name: 'Reading', user_id: 'bob' });
        for (const [task, tag] of [
            ['a', 'owned'],
            ['a', 'other'],
            ['b', 'owned'],
            ['c', 'foreign'],
            ['d', 'owned'],
            ['f', 'owned'],
        ] as const) {
            await tx.mutate.task_tag.insert({ task_id: task, tag_id: tag });
        }
    }),
});

async function withStore(exercise: (zero: Zero<typeof schema, undefined, ClientContext>) => Promise<void>) {
    const zero = new Zero<typeof schema, undefined, ClientContext>({
        schema,
        mutators: seed,
        context: { user_id: 'alice' },
        userID: 'alice',
        cacheURL: null,
        kvStore: 'mem',
        logLevel: 'error',
    });
    try {
        expect(await zero.mutate(seed.seed()).client).toEqual({ type: 'success' });
        await exercise(zero);
    } finally {
        await zero.close();
    }
}

describe('junction predicates', () => {
    it('filters through junctions without ordering their existence checks, while preserving root sorting and both owners', async () => {
        await withStore(async (zero) => {
            const query = runtime.models.Task.query.all().filter('tag', 'owned').orderBy('title', 'desc');
            expect((await zero.run(query.request())).map((row) => row.id)).toEqual(['a', 'b']);
            expect(await zero.run(runtime.models.Task.query.all().filter('tag', 'foreign').request())).toEqual([]);
            const grouped = runtime.models.Task.query
                .all()
                .filter('rules', {
                    match: 'any',
                    rules: [
                        { field: 'tag', value: 'Reading' },
                        { field: 'tag', value: 'Other' },
                    ],
                })
                .orderBy('title');
            expect((await zero.run(grouped.request())).map((row) => row.id)).toEqual(['b', 'a']);
        });
    });

    it('enforces authorization through a junction whose target has a default order', async () => {
        await withStore(async (zero) => {
            const query = authorized.models.Task.query.all().orderBy('title');
            expect((await zero.run(query.request())).map((row) => row.id)).toEqual(['b', 'a']);
        });
    });
});
