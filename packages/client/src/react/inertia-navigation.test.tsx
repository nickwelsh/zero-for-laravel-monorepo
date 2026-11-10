// @vitest-environment jsdom
import { act, cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { router } from '@inertiajs/react';
import { ZeroContext } from '@rocicorp/zero/react';
import type * as NativeReact from '@rocicorp/zero/react';
import { createBuilder, defineQueries, defineQuery } from '@rocicorp/zero';
import { schema, definitions } from '../../../../tests/interop/generated';
import { liveQuery } from '../live-query';
import { createInertiaAdapter, type NavigationRoute } from './inertia';
import type { Data } from './data';
import { createRef, type ComponentType } from 'react';

const native = vi.hoisted(() => ({ zero: undefined as any, requests: [] as any[] }));
const http = { visit: vi.fn<typeof router.visit>(), prefetch: vi.fn<typeof router.prefetch>() };
vi.mock('@rocicorp/zero/react', async (original) => ({
    ...(await original<typeof NativeReact>()),
    useZero: () => native.zero,
    useConnectionState: () => ({ name: 'connected' }),
    useQuery: (request: any) => {
        native.requests.push(request);
        return [[{ id: 'a', title: request.args.title }], { type: 'complete' }];
    },
}));

const queries = defineQueries({
    tasks: defineQuery(({ args }: { args: { title: string; __window?: number } }) =>
        createBuilder(schema)
            .tasks.where('title', args.title)
            .limit(args['__window'] ?? 3),
    ),
});
const factory = (args: { title: string }) => liveQuery(queries.tasks, args, definitions, 'Task', false, 2);
const route: NavigationRoute = {
    pattern: '^/tasks/(?<label>[^/]+)$',
    host: null,
    protocol: null,
    route: 'route-identity',
    component: 'tasks/index',
    defaults: {},
    bindings: { tasks: { query: 'tasks', one: false, parameters: { title: { route: 'label', type: 'string' } } } },
};
const adapters: ReturnType<typeof createInertiaAdapter>[] = [];
function adapter(navigation: readonly NavigationRoute[] = [route]) {
    const instance = createInertiaAdapter({ tasks: factory }, { navigation });
    adapters.push(instance);
    return instance;
}
function session() {
    return {
        context: { user_id: 'alice' },
        preload: vi.fn(() => ({ cleanup: vi.fn(), complete: Promise.resolve() })),
    };
}
function enter(element: HTMLElement) {
    fireEvent.mouseOver(element, { relatedTarget: document.body });
}
function tick(ms = 100) {
    act(() => {
        vi.advanceTimersByTime(ms);
    });
}
beforeEach(() => {
    vi.useFakeTimers();
    native.zero = session();
    native.requests.length = 0;
    http.visit.mockReset();
    http.prefetch.mockReset();
    vi.spyOn(router, 'visit').mockImplementation(http.visit);
    vi.spyOn(router, 'prefetch').mockImplementation(http.prefetch);
});
afterEach(() => {
    cleanup();
    adapters.splice(0).forEach((instance) => instance.dispose());
    vi.restoreAllMocks();
    vi.useRealTimers();
});

it('preloads on intent without HTTP, deduplicates links, and supplies instant bindings using the same query window', () => {
    const instance = adapter();
    const Link = instance.Link;
    const ref = createRef<unknown>();
    const onFocus = vi.fn();
    const href = { url: '/tasks/A%20B', method: 'get' as const, component: 'tasks/index' };
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            <Link href={href} instant preload ref={ref} onFocus={onFocus}>
                First
            </Link>
            <Link href={href} instant preload>
                Second
            </Link>
        </ZeroContext.Provider>,
    );
    const link = screen.getByRole('link', { name: 'First' });
    expect(ref.current).toBe(link);
    expect(link.hasAttribute('preload')).toBe(false);
    enter(link);
    fireEvent.mouseOut(link, { relatedTarget: document.body });
    tick();
    expect(native.zero.preload).not.toHaveBeenCalled();
    fireEvent.focus(link);
    tick();
    enter(screen.getByRole('link', { name: 'Second' }));
    tick();
    expect(onFocus).toHaveBeenCalledOnce();
    expect(native.zero.preload).toHaveBeenCalledOnce();
    expect(http.prefetch).not.toHaveBeenCalled();
    const request = native.zero.preload.mock.calls[0][0];
    expect(request.args).toEqual({
        title: 'A B',
        __route: 'route-identity',
        __routeArgs: { label: 'A B' },
        __window: 3,
    });
    fireEvent.click(link);
    const visit = http.visit.mock.calls[0]![1]!;
    expect(visit.component).toBe('tasks/index');
    const props = (visit.pageProps as Function)({}, { auth: { id: 'alice' } });
    expect(props.auth).toEqual({ id: 'alice' });
    expect(props['__zero'].bindings.tasks.args).toEqual({
        title: 'A B',
        __route: 'route-identity',
        __routeArgs: { label: 'A B' },
    });
    const Page = instance.wrap(
        ({ tasks }: { tasks: Data<readonly { title: string }[]> }) => <output>{tasks.data[0]?.title}</output>,
        { component: 'tasks/index', props },
    );
    view.rerender(
        <ZeroContext.Provider value={native.zero}>
            <Page {...props} />
        </ZeroContext.Provider>,
    );
    expect(screen.getByRole('status').textContent).toBe('A B');
    expect(native.requests.at(-1).args).toEqual(request.args);
});

it('keeps Inertia HTTP prefetch and its callbacks independent from Zero preload', () => {
    const Link = adapter().Link;
    const href = { url: '/tasks/A', method: 'get' as const, component: 'tasks/index' };
    const onPrefetching = vi.fn();
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            <Link href={href} instant prefetch onPrefetching={onPrefetching}>
                HTTP only
            </Link>
        </ZeroContext.Provider>,
    );
    enter(screen.getByRole('link'));
    tick(500);
    expect(http.prefetch).toHaveBeenCalledOnce();
    expect(http.prefetch.mock.calls[0]![1]!.onPrefetching).toBe(onPrefetching);
    expect(native.zero.preload).not.toHaveBeenCalled();
    view.rerender(
        <ZeroContext.Provider value={native.zero}>
            <Link href={href} instant preload prefetch>
                Both
            </Link>
        </ZeroContext.Provider>,
    );
    enter(screen.getByRole('link'));
    tick(500);
    expect(http.prefetch).toHaveBeenCalledTimes(2);
    expect(native.zero.preload).toHaveBeenCalledOnce();
});

it('renders unresolved live props as loading and replaces them with authoritative response arguments', () => {
    const instance = adapter([
        {
            ...route,
            bindings: { tasks: { query: 'tasks', one: false, parameters: null, options: { priority: [2, 7] } } },
        },
    ]);
    const Link = instance.Link;
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            <Link href={{ url: '/tasks/slug', method: 'get', component: 'tasks/index' }} instant preload>
                Unknown
            </Link>
        </ZeroContext.Provider>,
    );
    enter(screen.getByRole('link'));
    tick();
    expect(native.zero.preload).not.toHaveBeenCalled();
    fireEvent.click(screen.getByRole('link'));
    const props = (http.visit.mock.calls[0]![1]!.pageProps as Function)({}, {});
    const Page = instance.wrap(
        (({
            tasks,
        }: {
            tasks: Data<readonly { title: string }[]> & { inputs: { options: { priority: number[] } } };
        }) => (
            <output>
                {tasks.status === 'loading'
                    ? `Loading: ${tasks.inputs.options.priority.map(String).join(',')}`
                    : tasks.data[0]?.title}
            </output>
        )) as ComponentType<object>,
        { component: 'tasks/index', props },
    );
    view.rerender(<Page {...props} />);
    expect(screen.getByRole('status').textContent).toBe('Loading: 2,7');
    expect(native.requests).toHaveLength(0);
    const authoritative = {
        __zero: {
            version: 1,
            mode: 'replace',
            bindings: { tasks: { query: 'tasks', args: { title: 'Canonical ID' } } },
        },
    };
    view.rerender(<Page {...authoritative} />);
    expect(screen.getByRole('status').textContent).toBe('Canonical ID');
});

it('bounds speculation and cancels retained queries on identity changes, failure, expiry, and unmount', async () => {
    const Link = adapter().Link;
    const href = { url: '/tasks/A', method: 'get' as const, component: 'tasks/index' };
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            <Link href={href} instant preload>
                A
            </Link>
        </ZeroContext.Provider>,
    );
    fireEvent.focus(screen.getByRole('link'));
    tick();
    const first = native.zero.preload.mock.results[0].value;
    tick(30_000);
    expect(first.cleanup).toHaveBeenCalledOnce();
    fireEvent.focus(screen.getByRole('link'));
    tick();
    const second = native.zero.preload.mock.results[1].value;
    const previous = native.zero;
    native.zero = session();
    native.zero.context.user_id = 'bob';
    view.rerender(
        <ZeroContext.Provider value={native.zero}>
            <Link href={href} instant preload>
                A
            </Link>
        </ZeroContext.Provider>,
    );
    expect(second.cleanup).toHaveBeenCalledOnce();
    const failed = { cleanup: vi.fn(), complete: Promise.reject(new Error('Denied')) };
    native.zero.preload.mockReturnValueOnce(failed);
    fireEvent.focus(screen.getByRole('link'));
    tick();
    await act(async () => {});
    expect(failed.cleanup).toHaveBeenCalledOnce();
    fireEvent.focus(screen.getByRole('link'));
    tick();
    const final = native.zero.preload.mock.results.at(-1).value;
    view.unmount();
    expect(final.cleanup).toHaveBeenCalledOnce();
    expect(previous.preload).toHaveBeenCalledTimes(2);
});

it('does not speculate for external, non-GET, ambiguous, mismatched, or data-bearing destinations', () => {
    const Link = adapter([route, { ...route, pattern: '^/tasks/create$', component: null, bindings: {} }]).Link;
    const targets = [
        { href: { url: 'https://other.example/tasks/A', method: 'get' as const, component: 'tasks/index' } },
        { href: { url: '/tasks/A', method: 'post' as const, component: 'tasks/index' } },
        { href: { url: '/tasks/create', method: 'get' as const, component: 'tasks/index' } },
        { href: { url: '/tasks/A', method: 'get' as const, component: 'wrong' } },
        { href: { url: '/tasks/A', method: 'get' as const, component: 'tasks/index' }, data: { title: 'B' } },
    ];
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            {targets.map((props, index) => (
                <Link key={index} {...props} instant preload>
                    Target {index}
                </Link>
            ))}
        </ZeroContext.Provider>,
    );
    for (const link of view.container.querySelectorAll('a,button')) {
        enter(link as HTMLElement);
        tick();
    }
    expect(native.zero.preload).not.toHaveBeenCalled();
});

it('uses generated defaults and query inputs without instant visits and defers ambiguous PHP query shapes', () => {
    const Link = adapter([
        {
            ...route,
            pattern: '^/tasks(?:/(?<label>[^/]+))?$',
            defaults: { label: 'Default title' },
            bindings: {
                tasks: {
                    query: 'tasks',
                    one: false,
                    parameters: {
                        title: { route: 'label', type: 'string' },
                        filters: { query: ['term'] },
                    },
                },
            },
        },
    ]).Link;
    const view = render(
        <ZeroContext.Provider value={native.zero}>
            <Link href={{ url: '/tasks?term=A%2BB', method: 'get', component: 'tasks/index' }} preload>
                Default
            </Link>
        </ZeroContext.Provider>,
    );
    fireEvent.focus(screen.getByRole('link'));
    tick();
    expect(native.zero.preload.mock.calls[0][0].args).toEqual({
        title: 'Default title',
        filters: { term: 'A+B' },
        __route: 'route-identity',
        __routeArgs: { label: 'Default title' },
        __window: 3,
    });
    for (const query of ['term=A&term=B', 'term[nested]=A', 'term=%20A%20', 'term=']) {
        view.rerender(
            <ZeroContext.Provider value={native.zero}>
                <Link href={{ url: `/tasks/other?${query}`, method: 'get', component: 'tasks/index' }} preload>
                    Unknown
                </Link>
            </ZeroContext.Provider>,
        );
        fireEvent.focus(screen.getByRole('link'));
        tick();
    }
    expect(native.zero.preload).toHaveBeenCalledOnce();
});
