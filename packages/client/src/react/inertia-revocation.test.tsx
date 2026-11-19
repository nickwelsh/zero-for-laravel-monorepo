// @vitest-environment jsdom
import { act, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { StrictMode, type ComponentType } from 'react';
import { createRoot } from 'react-dom/client';
import { flushSync } from 'react-dom';
import type * as NativeReact from '@rocicorp/zero/react';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { liveQuery } from '../live-query';

interface AccessRequest {
    args: { generation: string; bindings: { binding: string }[] };
}
class AccessView {
    listener?: (rows: unknown[], type: 'unknown' | 'complete') => void;
    destroy = vi.fn();
    constructor(readonly request: AccessRequest) {}
    addListener(listener: NonNullable<AccessView['listener']>) {
        this.listener = listener;
        listener([], 'unknown');
        return vi.fn();
    }
    send(state: 'allowed' | 'denied') {
        this.listener?.(
            this.request.args.bindings.map(({ binding }) => ({
                id: `${this.request.args.generation}:${binding}`,
                generation: this.request.args.generation,
                binding,
                state,
                status: state === 'denied' ? 403 : null,
            })),
            'complete',
        );
    }
}
const native = vi.hoisted(() => ({
    zero: undefined as unknown as ReturnType<typeof mockZero>,
    views: [] as AccessView[],
}));
vi.mock('@rocicorp/zero/react', async (original) => ({
    ...(await original<typeof NativeReact>()),
    useZero: () => native.zero,
    useConnectionState: () => native.zero.connection.state.current,
    useQuery: () => [[], { type: 'complete' }],
}));
function mockZero() {
    return {
        schema,
        context: { user_id: 'alice' },
        connection: { state: { current: { name: 'connected' }, subscribe: () => () => {} } },
        materialize(request: AccessRequest) {
            const view = new AccessView(request);
            native.views.push(view);
            return view;
        },
    };
}
const queries = defineQueries({ tasks: defineQuery(() => createBuilder(schema).tasks) });
const factory = () => liveQuery(queries.tasks, {}, definitions, 'Task', false);
const replaceDocument = vi.fn<(url: string | URL) => void>();
function page(component: string, guarded = false) {
    return {
        component,
        url: `/${component}`,
        version: null,
        rescuedProps: [],
        rememberedState: {},
        flash: {},
        sharedProps: ['auth'],
        props: {
            errors: {},
            auth: { user: { id: 'alice' } },
            secret: 'private old page',
            __zero: {
                version: 1,
                mode: 'replace',
                bindings: guarded
                    ? {
                          tasks: {
                              query: 'tasks',
                              args: {},
                              access: { status: 403, revoked: { route: 'safe' } },
                          },
                      }
                    : {},
            },
        },
    };
}
beforeEach(() => {
    vi.resetModules();
    native.zero = mockZero();
    native.views.length = 0;
    vi.stubGlobal('scrollTo', vi.fn());
    window.history.replaceState(null, '', '/initial');
    replaceDocument.mockReset();
    const browser = window;
    // jsdom cannot navigate documents. Capture only that boundary while keeping
    // the real history/location reads and window event target for Inertia.
    const location = new Proxy(
        { replace: replaceDocument },
        {
            get(target, key) {
                if (key === 'replace') return target.replace;
                if (key === 'toString') return browser.location.toString.bind(browser.location);
                return Reflect.get(browser.location, key, browser.location);
            },
        },
    );
    vi.stubGlobal(
        'window',
        new Proxy(browser, {
            get(target, key) {
                if (key === 'location') return location;
                if (key === 'addEventListener') return target.addEventListener.bind(target);
                if (key === 'removeEventListener') return target.removeEventListener.bind(target);
                return Reflect.get(target, key, target);
            },
        }),
    );
});
afterEach(() => {
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it.each([
    'discarded resolver',
    'throwing observer',
    'throwing observer with server-only destination',
    'disposed adapter',
    'departed page',
] as const)('handles revocation with a %s through the real Inertia router and React commits', async (scenario) => {
    const { router, http } = await import('@inertiajs/react');
    const { createInertiaAdapter } = await import('./inertia');
    const failure = new Error('toast unavailable');
    const reported = vi.spyOn(console, 'error').mockImplementation(() => {});
    const throws = scenario.startsWith('throwing observer');
    const revoked = vi.fn(() => {
        expect(screen.queryByText('Protected contents')).toBeNull();
        if (throws) throw failure;
    });
    const adapter = createInertiaAdapter(
        { tasks: factory },
        {
            access: { onRevoked: revoked },
            navigation: [
                {
                    name: 'safe',
                    uri: 'safe',
                    domain: null,
                    pattern: '^/safe$',
                    host: null,
                    protocol: null,
                    route: 'safe',
                    component: 'safe',
                    client: scenario !== 'throwing observer with server-only destination',
                    defaults: {},
                    bindings: {},
                },
            ],
        },
    );
    const initial = page(scenario === 'discarded resolver' ? 'initial' : 'protected', true);
    const Protected = () => <p>Protected contents</p>;
    const Safe = (props: Record<string, unknown>) => <p>Safe destination: {JSON.stringify(props)}</p>;
    let release = () => {};
    const delayed = new Promise<void>((resolve) => {
        release = resolve;
    });
    const resolving = vi.fn();
    const resolveComponent = async (name: string, incoming: Parameters<typeof adapter.wrap>[1]) => {
        resolving(name);
        if (name === 'delayed') await delayed;
        return adapter.wrap(name === 'safe' ? Safe : Protected, incoming);
    };
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const client = http.getClient();
    const destination = {
        ...page('safe'),
        props: {
            errors: {},
            auth: initial.props.auth,
            __zero: { version: 1, mode: 'replace', bindings: {} },
        },
    };
    const request = vi.fn(async (config: Parameters<typeof client.request>[0]) => ({
        status: 200,
        headers: { 'x-inertia': 'true' },
        data: JSON.stringify(config.url.endsWith('/delayed') ? page('delayed') : destination),
    }));
    http.setClient({ request });
    let renderKey = 0;
    try {
        await act(async () =>
            router.init({
                initialPage: initial,
                resolveComponent,
                // Match Inertia React's synchronous swap, without App's process-wide
                // initialization singleton carrying state between test cases.
                swapComponent: async ({ component, page: incoming, preserveState }) => {
                    const Component = component as ComponentType<Record<string, unknown>>;
                    if (!preserveState) renderKey++;
                    flushSync(() =>
                        root.render(
                            <StrictMode>
                                <Component key={renderKey} {...incoming.props} />
                            </StrictMode>,
                        ),
                    );
                },
            }),
        );
        await waitFor(() => expect(native.views.length).toBeGreaterThan(0));
        const initialCheck = native.views.at(-1)!;
        await act(async () => initialCheck.send('denied'));
        expect(revoked).not.toHaveBeenCalled();
        expect(replaceDocument).not.toHaveBeenCalled();
        expect(screen.queryByText(/Safe destination/)).toBeNull();
        await act(async () => initialCheck.send('allowed'));
        expect(screen.getByText('Protected contents')).toBeTruthy();
        if (scenario === 'discarded resolver') {
            act(() => router.visit('/delayed'));
            await waitFor(() => expect(resolving).toHaveBeenCalledWith('delayed'));
            await act(async () =>
                router.replace({ component: 'protected', url: '/protected', props: page('protected', true).props }),
            );
            await waitFor(() => expect(window.location.pathname).toBe('/protected'));
            await act(async () => native.views.at(-1)!.send('allowed'));
            expect(screen.getByText('Protected contents')).toBeTruthy();
            await act(async () => release());
            expect(window.location.pathname).toBe('/protected');
            expect(screen.getByText('Protected contents')).toBeTruthy();
            await act(async () => initialCheck.send('denied'));
            expect(revoked).not.toHaveBeenCalled();
        }
        const active = native.views.at(-1)!;
        if (scenario === 'disposed adapter') adapter.dispose();
        if (scenario === 'departed page') {
            await act(async () => router.replace({ component: 'safe', url: '/elsewhere', props: destination.props }));
            await waitFor(() => expect(screen.getByText(/Safe destination/)).toBeTruthy());
        }
        await act(async () => active.send('denied'));
        if (scenario === 'disposed adapter' || scenario === 'departed page') {
            expect(revoked).not.toHaveBeenCalled();
            expect(replaceDocument).not.toHaveBeenCalled();
            expect(request).not.toHaveBeenCalled();
            expect(window.location.pathname).not.toBe('/safe');
            if (scenario === 'disposed adapter') expect(screen.getByRole('alert')).toBeTruthy();
            return;
        }
        expect(replaceDocument).toHaveBeenCalledExactlyOnceWith(`${window.location.origin}/safe`);
        expect(revoked).toHaveBeenCalledOnce();
        expect(screen.queryByText('Protected contents')).toBeNull();
        expect(screen.getByRole('alert')).toBeTruthy();
        expect(screen.queryByText(/Safe destination/)).toBeNull();
        if (throws) expect(reported).toHaveBeenCalledExactlyOnceWith(failure);
        else expect(reported).not.toHaveBeenCalled();
        expect(request).toHaveBeenCalledTimes(scenario === 'discarded resolver' ? 1 : 0);
        await act(async () => active.send('denied'));
        expect(revoked).toHaveBeenCalledOnce();
        expect(replaceDocument).toHaveBeenCalledOnce();
    } finally {
        release();
        await act(async () => root.unmount());
        container.remove();
        http.setClient(client);
        adapter.dispose();
        expect(native.views.every((watch) => watch.destroy.mock.calls.length === 1)).toBe(true);
    }
});

it('requests document replacement after history bootstrap with default and named remembered keys', async () => {
    const { router, http, useRemember } = await import('@inertiajs/react');
    const { createInertiaAdapter } = await import('./inertia');
    const adapter = createInertiaAdapter(
        { tasks: factory },
        {
            navigation: [
                {
                    name: 'safe',
                    uri: 'safe',
                    domain: null,
                    pattern: '^/safe$',
                    host: null,
                    protocol: null,
                    route: 'safe',
                    component: 'safe',
                    client: true,
                    defaults: {},
                    bindings: {},
                },
            ],
        },
    );
    function Protected() {
        const [draft] = useRemember('empty default draft');
        const [named] = useRemember('empty named draft', 'draft');
        useRemember('private old page selection', 'source-only');
        return (
            <p>
                Protected drafts: {draft} / {named}
            </p>
        );
    }
    function Safe(props: Record<string, unknown>) {
        const [draft] = useRemember('clean default draft');
        const [named] = useRemember('clean named draft', 'draft');
        return (
            <>
                <p>
                    Destination drafts: {draft} / {named}
                </p>
                <p>Destination props: {JSON.stringify(props)}</p>
            </>
        );
    }
    const rememberedState = {
        default: 'private default draft',
        draft: 'private named draft',
        'source-only': 'private old page selection',
    };
    const initial = { ...page('protected', true), rememberedState };
    window.history.replaceState({ page: initial }, '', initial.url);
    // Match createInertiaApp's bootstrap. Exercise both keys in one test:
    // resetModules does not reset Inertia's external history singleton.
    await router.decryptHistory();
    expect(router.restore()).toBe(rememberedState.default);
    expect(router.restore('draft')).toBe(rememberedState.draft);
    expect(router.restore('source-only')).toBe(rememberedState['source-only']);
    const destination = {
        ...page('safe'),
        props: {
            errors: {},
            auth: initial.props.auth,
            heading: 'Fresh destination heading',
            __zero: { version: 1, mode: 'replace', bindings: {} },
        },
    };
    const container = document.createElement('div');
    document.body.append(container);
    const root = createRoot(container);
    const client = http.getClient();
    const request = vi.fn(async () => ({
        status: 200,
        headers: { 'x-inertia': 'true' },
        data: JSON.stringify(destination),
    }));
    http.setClient({ request });
    let renderKey = 0;
    try {
        await act(async () =>
            router.init({
                initialPage: initial,
                resolveComponent: (name, incoming) => adapter.wrap(name === 'safe' ? Safe : Protected, incoming),
                swapComponent: async ({ component, page: incoming, preserveState }) => {
                    const Component = component as ComponentType<Record<string, unknown>>;
                    if (!preserveState) renderKey++;
                    flushSync(() =>
                        root.render(
                            <StrictMode>
                                <Component key={renderKey} {...incoming.props} />
                            </StrictMode>,
                        ),
                    );
                },
            }),
        );
        await waitFor(() => expect(native.views.length).toBeGreaterThan(0));
        const check = native.views.at(-1)!;
        await act(async () => check.send('allowed'));
        expect(screen.getByText('Protected drafts: private default draft / private named draft')).toBeTruthy();
        await waitFor(() => expect(window.history.state.page.rememberedState).toEqual(rememberedState));
        await act(async () => check.send('denied'));
        expect(replaceDocument).toHaveBeenCalledExactlyOnceWith(`${window.location.origin}/safe`);
        expect(request).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toBeTruthy();
        expect(screen.queryByText(/Protected drafts:/)).toBeNull();
        expect(screen.queryByText(/Destination drafts:/)).toBeNull();
        // The location capture does not execute navigation or clear state.
        // The initial history snapshot remains in this JS environment;
        // fresh-document isolation requires a real browser check.
        expect(router.restore()).toBe(rememberedState.default);
        expect(router.restore('draft')).toBe(rememberedState.draft);
        expect(router.restore('source-only')).toBe(rememberedState['source-only']);
    } finally {
        await act(async () => root.unmount());
        container.remove();
        http.setClient(client);
        adapter.dispose();
        expect(native.views.every((watch) => watch.destroy.mock.calls.length === 1)).toBe(true);
    }
});

it('runs the initial page revocation policy after asynchronous createInertiaApp bootstrap', async () => {
    const { createInertiaApp, router } = await import('@inertiajs/react');
    const { createInertiaAdapter } = await import('./inertia');
    const revoked = vi.fn();
    const adapter = createInertiaAdapter(
        { tasks: factory },
        {
            access: { onRevoked: revoked },
            navigation: [
                {
                    name: 'safe',
                    route: 'safe',
                    component: 'safe',
                    uri: 'safe',
                    pattern: '^/safe$',
                    domain: null,
                    host: null,
                    protocol: null,
                    defaults: {},
                    bindings: {},
                },
            ],
        },
    );
    const Protected = () => <p>Initial protected page</p>;
    const container = document.createElement('div');
    container.id = 'revocation-app';
    document.body.append(container);
    const root = createRoot(container);
    const navigated = vi.fn();
    const unsubscribe = router.on('navigate', navigated);
    try {
        // Laravel cannot see the browser fragment. Inertia adds it to the
        // shallow copy it resolves again without swapping the mounted page.
        window.history.replaceState(null, '', '/protected#details');
        await act(async () =>
            createInertiaApp({
                id: container.id,
                page: page('protected', true),
                progress: false,
                resolve: async (_, incoming) => {
                    await Promise.resolve();
                    return adapter.wrap(Protected, incoming);
                },
                setup: ({ App, props }) => {
                    root.render(
                        <StrictMode>
                            <App {...props} />
                        </StrictMode>,
                    );
                },
            }),
        );
        await waitFor(() => expect(navigated).toHaveBeenCalled());
        const check = native.views.at(-1)!;
        await act(async () => check.send('allowed'));
        expect(screen.getByText('Initial protected page')).toBeTruthy();
        await act(async () => check.send('denied'));
        expect(screen.queryByText('Initial protected page')).toBeNull();
        expect(screen.getByRole('alert')).toBeTruthy();
        expect(revoked).toHaveBeenCalledOnce();
        expect(replaceDocument).toHaveBeenCalledExactlyOnceWith(`${window.location.origin}/safe`);
    } finally {
        unsubscribe();
        await act(async () => root.unmount());
        adapter.dispose();
        container.remove();
    }
});

it.each(['client departure', 'adapter disposal', 'page unmount', 'disposal during replacement'] as const)(
    'cancels pending document revocation and leaves no Inertia response after %s',
    async (scenario) => {
        const { router, http } = await import('@inertiajs/react');
        const { createInertiaAdapter } = await import('./inertia');
        const adapter = createInertiaAdapter(
            { tasks: factory },
            {
                navigation: [
                    {
                        name: 'safe',
                        uri: 'safe',
                        domain: null,
                        pattern: '^/safe$',
                        host: null,
                        protocol: null,
                        route: 'safe',
                        component: 'safe',
                        client: true,
                        defaults: {},
                        bindings: {},
                    },
                ],
            },
        );
        const Protected = () => <p>Protected contents</p>;
        const Safe = () => <p>Stale Inertia destination</p>;
        const Elsewhere = () => <p>Chosen elsewhere page</p>;
        const container = document.createElement('div');
        document.body.append(container);
        const root = createRoot(container);
        const client = http.getClient();
        let pendingDocument: string | URL | undefined;
        replaceDocument.mockImplementation((url) => {
            pendingDocument = url;
            if (scenario === 'disposal during replacement') adapter.dispose();
        });
        const stopDocument = vi.spyOn(window, 'stop').mockImplementation(() => {
            pendingDocument = undefined;
        });
        let release = () => {};
        const request = vi.fn(
            () =>
                new Promise<{ status: number; headers: Record<string, string>; data: string }>((resolve) => {
                    release = () =>
                        resolve({
                            status: 200,
                            headers: { 'x-inertia': 'true' },
                            data: JSON.stringify({
                                ...page('safe'),
                                props: { errors: {}, __zero: { version: 1, mode: 'replace', bindings: {} } },
                            }),
                        });
                }),
        );
        http.setClient({ request });
        let renderKey = 0;
        try {
            await act(async () =>
                router.init({
                    initialPage: page('protected', true),
                    resolveComponent: (name, incoming) =>
                        adapter.wrap(name === 'safe' ? Safe : name === 'elsewhere' ? Elsewhere : Protected, incoming),
                    swapComponent: async ({ component, page: incoming, preserveState }) => {
                        const Component = component as ComponentType<Record<string, unknown>>;
                        if (!preserveState) renderKey++;
                        flushSync(() =>
                            root.render(
                                <StrictMode>
                                    <Component key={renderKey} {...incoming.props} />
                                </StrictMode>,
                            ),
                        );
                    },
                }),
            );
            await waitFor(() => expect(native.views.length).toBeGreaterThan(0));
            const check = native.views.at(-1)!;
            await act(async () => check.send('allowed'));
            expect(screen.getByText('Protected contents')).toBeTruthy();
            await act(async () => check.send('denied'));
            expect(screen.getByRole('alert')).toBeTruthy();
            expect(screen.queryByText('Protected contents')).toBeNull();
            if (scenario !== 'disposal during replacement') {
                expect(pendingDocument).toBe(`${window.location.origin}/safe`);
                // Speculative resolution must not cancel the committed owner's load.
                adapter.wrap(Safe, page('discarded'));
                expect(stopDocument).not.toHaveBeenCalled();
            }
            if (scenario === 'client departure') {
                await act(async () =>
                    router.replace({
                        component: 'elsewhere',
                        url: '/elsewhere',
                        props: {
                            errors: {},
                            __zero: { version: 1, mode: 'replace', bindings: {} },
                        },
                    }),
                );
                await waitFor(() => expect(screen.getByText('Chosen elsewhere page')).toBeTruthy());
            } else if (scenario === 'adapter disposal') adapter.dispose();
            else if (scenario === 'page unmount') await act(async () => root.unmount());
            const departedURL = window.location.pathname;
            await act(async () => release());
            // Model a late native response at the platform boundary. The real
            // browser probe verifies that stop() aborts this document request.
            if (pendingDocument) window.history.replaceState(null, '', pendingDocument);
            expect(window.location.pathname).toBe(departedURL);
            expect(stopDocument).toHaveBeenCalledOnce();
            expect(screen.queryByText('Stale Inertia destination')).toBeNull();
            if (scenario === 'client departure') {
                expect(window.location.pathname).toBe('/elsewhere');
                expect(screen.getByText('Chosen elsewhere page')).toBeTruthy();
            } else if (scenario !== 'page unmount') expect(screen.getByRole('alert')).toBeTruthy();
            else expect(container.textContent).toBe('');
            expect(replaceDocument).toHaveBeenCalledExactlyOnceWith(`${window.location.origin}/safe`);
            expect(request).not.toHaveBeenCalled();
        } finally {
            release();
            await act(async () => root.unmount());
            container.remove();
            http.setClient(client);
            adapter.dispose();
            expect(native.views.every((watch) => watch.destroy.mock.calls.length === 1)).toBe(true);
        }
    },
);
