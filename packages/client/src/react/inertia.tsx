import { router } from '@inertiajs/react';
import { inertiaURLAdapter, inertiaURLVisit } from './inertia-url';
export { inertiaURLAdapter } from './inertia-url';
import {
    createContext,
    createElement,
    useContext,
    useLayoutEffect,
    useRef,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';
import { object, selectionKey } from '../validation';
import { useData, QueryAccessError, type Data, type ReadSelection, type QueryInputs } from './data';
import { StartingZero } from './provider';
import { AccessBoundary, type AccessOptions } from './access';
import { createNavigationLink, type NavigationRoute, type PageBinding } from './inertia-navigation';
export type { NavigationRoute, ZeroLinkProps } from './inertia-navigation';

type Binding = PageBinding;
interface Metadata {
    version: 1;
    mode: 'replace' | 'patch';
    awaitingMutation?: true;
    pageProps?: Record<string, unknown>;
    bindings: Record<string, Binding | null>;
}
interface TransportPage {
    component: string;
    props: Record<string, unknown>;
}
type BindingFactories = Record<string, (args: never) => ReadSelection>;
export interface InertiaInputOptions {
    navigation?: readonly NavigationRoute[];
    /** Component name -> live prop -> input field -> URL query parameter. Scalars only. */
    url?: Record<string, Record<string, Record<string, string>>>;
    history?: 'replace' | 'push';
    access?: AccessOptions;
}
const EffectiveProps = createContext<Record<string, unknown> | undefined>(undefined);

/** Unlike upstream usePage(), this context contains injected live props. */
// oxlint-disable-next-line typescript/no-unnecessary-type-parameters -- The caller supplies its generated page contract, as with Inertia's usePage.
export function useLiveProps<P extends object>(): P {
    const props = useContext(EffectiveProps);
    if (!props) throw new Error('useLiveProps requires a Zero-wrapped Inertia page.');
    return props as P;
}

function metadata(input: unknown): Metadata {
    if (input === undefined) return { version: 1, mode: 'replace', bindings: {} };
    const source = object(input, '__zero');
    if (source.version !== 1 || (source.mode !== 'replace' && source.mode !== 'patch')) {
        throw new Error('Incompatible Zero page contract. Reload the application.');
    }
    const bindings: Metadata['bindings'] = Object.create(null);
    for (const [prop, value] of Object.entries(object(source.bindings, '__zero.bindings'))) {
        if (prop === '__zero') throw new Error('The __zero prop is reserved.');
        if (value === null) bindings[prop] = null;
        else {
            const binding = object(value, `__zero.bindings.${prop}`);
            if (typeof binding.query !== 'string') throw new Error('Invalid live query binding.');
            const access = binding.access === undefined ? undefined : object(binding.access, 'access');
            if (access && access.status !== 403 && access.status !== 404) throw new Error('Invalid access contract.');
            if (access?.scope !== undefined && access.scope !== 'page') throw new Error('Invalid access scope.');
            const revoked = access?.revoked === undefined ? undefined : object(access.revoked, 'revoked');
            if (
                revoked &&
                (typeof revoked.route !== 'string' ||
                    !revoked.route ||
                    (revoked.message !== undefined && typeof revoked.message !== 'string'))
            )
                throw new Error('Invalid revocation response.');
            const inputOptions = Object.fromEntries(
                Object.entries(object(binding.options ?? {}, 'options')).map(([field, choices]) => {
                    if (
                        !Array.isArray(choices) ||
                        choices.some((choice) => typeof choice !== 'string' && typeof choice !== 'number')
                    )
                        throw new Error('Invalid input options.');
                    return [field, choices as (string | number)[]];
                }),
            );
            bindings[prop] = {
                query: binding.query,
                args: object(binding.args, 'args'),
                ...(access
                    ? {
                          access: {
                              status: access.status as 403 | 404,
                              ...(access.scope === 'page' ? { scope: 'page' as const } : {}),
                              ...(revoked
                                  ? {
                                        revoked: {
                                            route: revoked.route as string,
                                            ...(revoked.message === undefined
                                                ? {}
                                                : { message: revoked.message as string }),
                                        },
                                    }
                                  : {}),
                          },
                      }
                    : {}),
                ...(binding.pending === true
                    ? { pending: true, one: binding.one === true, options: inputOptions }
                    : {}),
            };
        }
    }
    const pageProps = source.pageProps === undefined ? undefined : object(source.pageProps, '__zero.pageProps');
    if (pageProps && !Object.values(bindings).some((binding) => binding?.access?.scope === 'page')) {
        throw new Error('Protected ordinary props require a class guard.');
    }
    return {
        version: 1,
        mode: source.mode,
        bindings,
        ...(pageProps ? { pageProps } : {}),
        ...(source.awaitingMutation === true ? { awaitingMutation: true } : {}),
    };
}

/** Clear page-owned live bindings when making a client-side visit to a page shell. */
export function withoutLiveProps<P extends Record<string, unknown>>(props: P) {
    const clean: Record<string, unknown> = { ...props };
    for (const prop of Object.keys(metadata(props['__zero']).bindings)) delete clean[prop];
    delete clean['__zero'];
    return { ...clean, __zero: { version: 1 as const, mode: 'replace' as const, bindings: {} } } as Omit<
        P,
        '__zero'
    > & { __zero: { version: 1; mode: 'replace'; bindings: Record<string, never> } };
}

function normalizePage(page: TransportPage, previous?: TransportPage): Metadata {
    const incoming = metadata(page.props['__zero']);
    if (incoming.mode === 'patch' && (!previous || previous.component !== page.component)) {
        throw new Error('A partial Zero response requires the current page contract.');
    }
    // Initial resolution and client visits can share props with the mounted
    // page. Preparing another transport page must not mutate that snapshot.
    const props = { ...page.props };
    for (const key of Object.keys(incoming.pageProps ?? {})) delete props[key];
    const bindings: Metadata['bindings'] = Object.assign(
        Object.create(null),
        incoming.mode === 'patch' ? metadata(previous?.props['__zero']).bindings : {},
    );
    for (const [prop, binding] of Object.entries(incoming.bindings)) {
        if (binding === null) delete bindings[prop];
        else {
            // Inertia has merged ordinary props before beforeUpdate. A newly
            // selected live binding replaces any earlier ordinary snapshot.
            if (incoming.mode === 'patch') delete props[prop];
            else if (Object.hasOwn(props, prop)) throw new Error(`Ordinary prop ${prop} collides with a live binding.`);
            bindings[prop] = binding;
        }
    }
    // History stores a complete serializable contract, never a patch or live rows.
    const snapshot: Metadata = { ...incoming, mode: 'replace', bindings };
    props['__zero'] = snapshot;
    page.props = props;
    return snapshot;
}

function Subscription({
    selection,
    access,
    pageGuard,
    children,
}: {
    selection: ReadSelection;
    access?: AccessOptions | undefined;
    pageGuard: boolean;
    children: (data: Data<unknown>) => ReactNode;
}) {
    const data = useData(selection);
    if (access) {
        if (data.error instanceof QueryAccessError)
            return (
                access.onError?.(data.error.status) ?? (
                    <div role="alert" data-zero-access={data.error.status}>
                        {data.error.message}
                    </div>
                )
            );
        if (!data.complete || data.error || (!pageGuard && selection.one && data.data === undefined))
            return access.onPending?.() ?? <output data-zero-access="unknown">Checking access…</output>;
    }
    return children(data);
}

function InputSubscription({
    binding,
    factory,
    starting,
    synchronize,
    prop,
    access,
    children,
}: {
    binding: Binding;
    factory: (args: unknown) => ReadSelection;
    starting: boolean;
    synchronize: (args: Record<string, unknown>, values: object) => boolean;
    prop: string;
    access: AccessOptions;
    children: (data: Data<unknown> & { inputs: QueryInputs<Record<string, unknown>, object> }) => ReactNode;
}) {
    const source = selectionKey(binding);
    const [stored, setStored] = useState(() => ({
        source,
        args: binding.args,
        initial: binding.args,
        pending: [] as string[],
    }));
    let state = stored;
    if (stored.source !== source) {
        const acknowledgement = stored.pending.indexOf(selectionKey(binding.args));
        state =
            acknowledgement === -1
                ? { source, args: binding.args, initial: binding.args, pending: [] }
                : { ...stored, source, pending: stored.pending.slice(acknowledgement + 1) };
        setStored(state);
    }
    const latest = useRef<typeof state | undefined>(state);
    useLayoutEffect(() => {
        latest.current = state;
        return () => {
            latest.current = undefined;
        };
    }, [state]);
    const selection = factory(state.args);
    function update(patch: object | ((values: Readonly<Record<string, unknown>>) => object), reset = false) {
        const before = latest.current;
        if (!before || before.source !== source) return;
        const current = factory(before.args);
        if (!current.input) throw new Error('This query has no generated editable input contract.');
        const values = object(current.input.values, 'inputs');
        const changes = object(typeof patch === 'function' ? patch(values) : patch, 'inputs');
        const merged = reset ? (factory(before.initial).input?.values ?? {}) : { ...values, ...changes };
        const nextValues = Object.fromEntries(Object.entries(merged).filter(([, value]) => value !== undefined));
        const args = object(current.input.apply(nextValues), 'args');
        const next = factory(args);
        if (selectionKey(args) === selectionKey(before.args)) return;
        const synchronized = synchronize(args, next.input?.values ?? {});
        latest.current = {
            ...before,
            args,
            pending: synchronized ? [...before.pending, selectionKey(args)] : [],
        };
        setStored(latest.current);
    }
    const inputs: QueryInputs<Record<string, unknown>, object> = {
        values: object(selection.input?.values ?? {}, 'inputs'),
        options: selection.input?.options ?? {},
        set: (patch) => update(patch),
        reset: () => update({}, true),
    };
    // oxlint-disable-next-line react/refs -- Only input event handlers access the ref; rendering reads immutable values.
    if (starting) return children({ ...loading(selection), inputs });
    const subscription = (
        <Subscription
            selection={selection}
            access={binding.access ? access : undefined}
            pageGuard={binding.access?.scope === 'page'}
        >
            {(data) => children({ ...data, inputs })}
        </Subscription>
    );
    return binding.access ? (
        <AccessBoundary binding={{ ...binding, args: state.args }} prop={prop} options={access}>
            {subscription}
        </AccessBoundary>
    ) : (
        subscription
    );
}

const noop = () => {};

function loading(selection: Pick<ReadSelection, 'one'>): Data<unknown> {
    return {
        data: selection.one ? undefined : [],
        status: 'loading',
        complete: false,
        enabled: true,
        error: undefined,
        connection: { name: 'initializing' },
        details: { type: 'unknown' },
        infinite: undefined,
        hasMore: undefined,
        isLoadingMore: false,
        capped: false,
        exhausted: false,
        stopReason: undefined,
        loadMore: noop,
        hasNextPage: undefined,
        hasPreviousPage: false,
        isChangingPage: false,
        nextPage: noop,
        previousPage: noop,
        retry: noop,
        render: (handlers) => handlers.onLoading(),
    };
}

/** Install once before createInertiaApp; wrap components in its resolve callback. */
export function createInertiaAdapter(factories: BindingFactories, options: InertiaInputOptions = {}) {
    const navigation = createNavigationLink(factories, options.navigation ?? []);
    let current: TransportPage | undefined;
    let active: { mount: object; snapshot: object; handled: boolean } | undefined;
    let disposed = false;
    let cancelRedirect: (() => void) | undefined;
    const wrappers = new WeakMap<object, unknown>();
    const pages = new WeakMap<object, TransportPage>();
    const guardedPages = new Set(
        options.navigation
            ?.filter((route) => Object.values(route.bindings).some((binding) => binding.access?.scope === 'page'))
            .map((route) => route.component),
    );
    function cancelNavigation() {
        const cancel = cancelRedirect;
        cancelRedirect = undefined;
        cancel?.();
    }
    function prepare(page: TransportPage) {
        const snapshot = normalizePage(page, current);
        if (Object.values(snapshot.bindings).some((binding) => binding?.access?.scope === 'page')) {
            guardedPages.add(page.component);
        }
        pages.set(snapshot, page);
    }
    function observePage({ detail: { page } }: { detail: { page: TransportPage } }) {
        const snapshot = page.props['__zero'];
        // For prepared pages, React's layout commit/cleanup owns the handoff.
        // Inertia also announces its second bootstrap resolution without a swap.
        if (active && snapshot && typeof snapshot === 'object' && pages.has(snapshot)) return;
        current = page;
        if (active && active.snapshot !== snapshot) {
            active = undefined;
            cancelNavigation();
        }
    }
    function committed(page: TransportPage | undefined, mount: object, snapshot: object) {
        cancelNavigation();
        current = page ?? current;
        const owner = { mount, snapshot, handled: false };
        active = owner;
        return () => {
            if (active === owner) {
                cancelNavigation();
                active = undefined;
            }
        };
    }
    const dispose =
        typeof window === 'undefined'
            ? []
            : [
                  inertiaURLAdapter.subscribe(noop),
                  router.on('beforeUpdate', ({ detail }) => prepare(detail.page)),
                  router.on('navigate', observePage),
                  router.on('clientVisit', observePage),
              ];
    return {
        Link: navigation.Link,
        dispose: () => {
            disposed = true;
            cancelNavigation();
            navigation.dispose();
            dispose.forEach((unsubscribe) => unsubscribe());
        },
        wrap<P extends object>(Component: ComponentType<P>, page?: TransportPage): ComponentType<P> {
            if (page) prepare(page);
            const cached = wrappers.get(Component);
            if (cached) return cached as ComponentType<P>;
            function Wrapped(props: P) {
                const starting = useContext(StartingZero);
                const ordinary = Object.fromEntries(Object.entries(props));
                const contract = metadata(ordinary['__zero']);
                const snapshot = object(ordinary['__zero'] ?? {}, '__zero');
                const [mount] = useState(() => ({}));
                // Resolving a lazy component is speculative: Inertia can discard
                // it. Claim ownership only once React commits the page, before
                // a nested boundary's passive revocation effect can run.
                useLayoutEffect(() => committed(pages.get(snapshot) ?? page, mount, snapshot), [mount, snapshot]);
                if (contract.mode !== 'replace')
                    throw new Error('Zero page metadata was not normalized before rendering.');
                delete ordinary['__zero'];
                const entries = Object.entries(contract.bindings).filter(
                    (entry): entry is [string, Binding] => entry[1] !== null,
                );
                if (
                    contract.awaitingMutation ||
                    // An unresolved instant visit can arrive with only shared
                    // props. An absent contract is not a class approval.
                    (guardedPages.has((pages.get(snapshot) ?? page)?.component ?? null) &&
                        !entries.some(([, binding]) => binding.access?.scope === 'page')) ||
                    entries.some(([, binding]) => binding.access && (starting || binding.pending))
                ) {
                    return <output data-zero-access="unknown">Checking access…</output>;
                }
                function accessOptions(binding: Binding): AccessOptions {
                    return {
                        ...options.access,
                        onRevoked(event) {
                            const owner = active;
                            if (
                                disposed ||
                                !owner ||
                                owner.mount !== mount ||
                                owner.snapshot !== snapshot ||
                                owner.handled
                            )
                                return;
                            owner.handled = true;
                            const response = binding.access?.revoked;
                            try {
                                options.access?.onRevoked?.(event);
                            } catch (error) {
                                // An optional observer must not unmount the Inertia
                                // app before mandatory document navigation starts.
                                console.error(error);
                            } finally {
                                if (response && owner === active && !disposed) {
                                    const cancel = navigation.redirect(
                                        response.route,
                                        object(binding.args['__routeArgs'] ?? {}, 'route arguments'),
                                    );
                                    // beforeunload can synchronously end this owner
                                    // before location.replace returns its stop handle.
                                    if (disposed || owner !== active) cancel?.();
                                    else cancelRedirect = cancel;
                                }
                            }
                        },
                    };
                }
                function render(index: number, effective: Record<string, unknown>): ReactNode {
                    const entry = entries[index];
                    if (!entry)
                        return (
                            <EffectiveProps.Provider value={effective}>
                                {createElement(Component, effective as P)}
                            </EffectiveProps.Provider>
                        );
                    const [prop, binding] = entry;
                    if (Object.hasOwn(effective, prop))
                        throw new Error(`Ordinary prop ${prop} collides with a live binding.`);
                    if (binding.pending) {
                        return render(index + 1, {
                            ...effective,
                            [prop]: {
                                ...loading({ one: binding.one ?? false }),
                                inputs: { values: {}, options: binding.options ?? {}, set: noop, reset: noop },
                            },
                        });
                    }
                    if (!Object.hasOwn(factories, binding.query))
                        throw new Error('Unknown Zero query contract. Reload the application.');
                    const factory = factories[binding.query] as (args: unknown) => ReadSelection;
                    const synchronize = (args: Record<string, unknown>, values: object) => {
                        const fields = options.url?.[current?.component ?? page?.component ?? '']?.[prop];
                        if (!fields) return false;
                        const url = new URL(inertiaURLAdapter.getURL());
                        const input = object(values, 'inputs');
                        for (const [field, parameter] of Object.entries(fields)) {
                            const value = input[field];
                            if (value === undefined) url.searchParams.delete(parameter);
                            else if (value === null) url.searchParams.set(parameter, '');
                            else if (
                                typeof value === 'string' ||
                                typeof value === 'number' ||
                                typeof value === 'boolean'
                            )
                                url.searchParams.set(
                                    parameter,
                                    typeof value === 'boolean' ? (value ? '1' : '0') : String(value),
                                );
                            else throw new Error(`URL input ${field} must be a scalar.`);
                        }
                        const visit = {
                            url: url.pathname + url.search + url.hash,
                            preserveState: true,
                            preserveScroll: true,
                            props: (historyProps: Record<string, unknown>) => {
                                const previous = metadata(historyProps['__zero']);
                                return {
                                    ...historyProps,
                                    __zero: {
                                        ...previous,
                                        mode: 'replace',
                                        bindings: {
                                            ...previous.bindings,
                                            [prop]: { ...binding, args },
                                        },
                                    },
                                };
                            },
                        };
                        inertiaURLVisit(visit, options.history ?? 'replace');
                        return true;
                    };
                    return (
                        <InputSubscription
                            key={prop + binding.query}
                            binding={binding}
                            factory={factory}
                            starting={starting}
                            synchronize={synchronize}
                            prop={prop}
                            access={accessOptions(binding)}
                        >
                            {(data) => render(index + 1, { ...effective, [prop]: data })}
                        </InputSubscription>
                    );
                }
                return render(0, { ...ordinary, ...contract.pageProps });
            }
            // Preserve Inertia persistent layouts and component statics.
            Object.assign(Wrapped, Component);
            wrappers.set(Component, Wrapped);
            return Wrapped;
        },
    };
}
