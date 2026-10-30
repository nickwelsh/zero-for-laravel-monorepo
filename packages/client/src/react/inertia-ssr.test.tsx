// @vitest-environment jsdom
import { act } from 'react';
import { renderToString } from 'react-dom/server';
import { hydrateRoot } from 'react-dom/client';
import { expect, it } from 'vite-plus/test';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { createControllerMutationBindings } from '../controller-mutation.fixture';
import { liveQuery } from '../live-query';
import { createInertiaAdapter, useLiveProps } from './inertia';
import { createZeroProvider } from './provider';
import { useControllerMutation } from './controller-mutation';
import type { Data } from './data';

it('renders stable live-prop shells and hydrates before exposing native cached rows', async () => {
    const mutations = createControllerMutationBindings(schema, definitions, {
        'ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected': async () => ({ pending: true }),
    });
    const Provider = createZeroProvider(
        schema,
        mutations.mutators,
        { cacheURL: null, kvStore: 'mem', logLevel: 'error' },
        true,
    );
    const queries = defineQueries({
        tasks: defineQuery(({ args }: { args: { title: string } }) =>
            createBuilder(schema).tasks.where('title', args.title),
        ),
    });
    const adapter = createInertiaAdapter({
        tasks: (args: { title: string }) => liveQuery(queries.tasks, args, definitions, 'Task', false),
    });
    function Page() {
        const { heading, tasks } = useLiveProps<{ heading: string; tasks: Data<readonly { title: string }[]> }>();
        const create = useControllerMutation(
            mutations.actions['ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@store'],
        );
        return (
            <main>
                <h1>{heading}</h1>
                <p>
                    {tasks.status}:{tasks.data.length}:{create.state.status}
                </p>
            </main>
        );
    }
    const page = {
        component: 'tasks',
        props: {
            heading: 'Tasks shell',
            __zero: {
                version: 1,
                mode: 'replace',
                bindings: { tasks: { query: 'tasks', args: { title: 'one' } } },
            },
        },
    };
    const Wrapped = adapter.wrap(Page, page);
    const element = (
        <Provider userID="ssr" context={{ user_id: 'alice' }}>
            <Wrapped {...page.props} />
        </Provider>
    );
    const html = renderToString(element);
    expect(html).toContain('Tasks shell');
    expect(html.replaceAll('<!-- -->', '')).toContain('loading:0:idle');
    const container = document.createElement('div');
    container.innerHTML = html;
    document.body.append(container);
    const errors: unknown[] = [];
    let root: ReturnType<typeof hydrateRoot> | undefined;
    try {
        await act(async () => {
            root = hydrateRoot(container, element, { onRecoverableError: (error) => errors.push(error) });
        });
        expect(errors).toEqual([]);
        expect(container.querySelectorAll('h1')).toHaveLength(1);
        expect(JSON.stringify(page)).not.toContain('loading');
    } finally {
        await act(async () => root?.unmount());
        container.remove();
        adapter.dispose();
    }
});
