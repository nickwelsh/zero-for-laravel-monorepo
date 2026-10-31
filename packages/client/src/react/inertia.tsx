import { router } from '@inertiajs/react';
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
import { useData, type Data, type ReadSelection, type QueryInputs } from './data';
import { StartingZero } from './provider';

interface Binding {
    query: string;
    args: Record<string, unknown>;
}
interface Metadata {
    version: 1;
    mode: 'replace' | 'patch';
    bindings: Record<string, Binding | null>;
}
interface TransportPage {
    component: string;
    props: Record<string, unknown>;
}
type BindingFactories = Record<string, (args: never) => ReadSelection>;
export interface InertiaInputOptions {
    /** Component name -> live prop -> input field -> URL query parameter. Scalars only. */
    url?: Record<string, Record<string, Record<string, string>>>;
    history?: 'replace' | 'push';
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
            bindings[prop] = { query: binding.query, args: object(binding.args, 'args') };
        }
    }
    return { version: 1, mode: source.mode, bindings };
}

function normalizePage(page: TransportPage, previous?: TransportPage): void {
    const incoming = metadata(page.props['__zero']);
    if (incoming.mode === 'patch' && (!previous || previous.component !== page.component)) {
        throw new Error('A partial Zero response requires the current page contract.');
    }
    const bindings: Metadata['bindings'] = Object.assign(
        Object.create(null),
        incoming.mode === 'patch' ? metadata(previous?.props['__zero']).bindings : {},
    );
    for (const [prop, binding] of Object.entries(incoming.bindings)) {
        if (binding === null) delete bindings[prop];
        else {
            // Inertia has merged ordinary props before beforeUpdate. A newly
            // selected live binding replaces any earlier ordinary snapshot.
            if (incoming.mode === 'patch') delete page.props[prop];
            else if (Object.hasOwn(page.props, prop))
                throw new Error(`Ordinary prop ${prop} collides with a live binding.`);
            bindings[prop] = binding;
        }
    }
    // History stores a complete serializable contract, never a patch or live rows.
    page.props['__zero'] = { version: 1, mode: 'replace', bindings };
}

function Subscription({
    selection,
    children,
}: {
    selection: ReadSelection;
    children: (data: Data<unknown>) => ReactNode;
}) {
    return children(useData(selection));
}

function InputSubscription({
    binding,
    factory,
    starting,
    synchronize,
    children,
}: {
    binding: Binding;
    factory: (args: unknown) => ReadSelection;
    starting: boolean;
    synchronize: (args: Record<string, unknown>, values: object) => boolean;
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
    return <Subscription selection={selection}>{(data) => children({ ...data, inputs })}</Subscription>;
}

const noop = () => {};

function loading(selection: ReadSelection): Data<unknown> {
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
    let current: TransportPage | undefined;
    let pendingURL: URL | undefined;
    const pendingURLs = new Set<string>();
    const wrappers = new WeakMap<object, unknown>();
    const dispose =
        typeof window === 'undefined'
            ? []
            : [
                  router.on('beforeUpdate', ({ detail }) => normalizePage(detail.page, current)),
                  router.on('navigate', ({ detail }) => {
                      current = detail.page;
                      const url = new URL(detail.page.url, window.location.href).href;
                      if (!pendingURLs.has(url) || pendingURL?.href === url) {
                          pendingURL = undefined;
                          pendingURLs.clear();
                      } else pendingURLs.delete(url);
                  }),
              ];
    return {
        dispose: () => dispose.forEach((unsubscribe) => unsubscribe()),
        wrap<P extends object>(Component: ComponentType<P>, page?: TransportPage): ComponentType<P> {
            if (page) {
                normalizePage(page, current);
                current ??= page;
            }
            const cached = wrappers.get(Component);
            if (cached) return cached as ComponentType<P>;
            function Wrapped(props: P) {
                const starting = useContext(StartingZero);
                const ordinary = Object.fromEntries(Object.entries(props));
                const contract = metadata(ordinary['__zero']);
                if (contract.mode !== 'replace')
                    throw new Error('Zero page metadata was not normalized before rendering.');
                delete ordinary['__zero'];
                const entries = Object.entries(contract.bindings).filter(
                    (entry): entry is [string, Binding] => entry[1] !== null,
                );
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
                    if (!Object.hasOwn(factories, binding.query))
                        throw new Error('Unknown Zero query contract. Reload the application.');
                    const factory = factories[binding.query] as (args: unknown) => ReadSelection;
                    const synchronize = (args: Record<string, unknown>, values: object) => {
                        const fields = options.url?.[current?.component ?? page?.component ?? '']?.[prop];
                        if (!fields) return false;
                        const url = new URL(pendingURL ?? window.location.href);
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
                                            [prop]: { query: binding.query, args },
                                        },
                                    },
                                };
                            },
                        };
                        pendingURL = url;
                        pendingURLs.add(url.href);
                        if (options.history === 'push') router.push(visit);
                        else router.replace(visit);
                        return true;
                    };
                    return (
                        <InputSubscription
                            key={prop + binding.query}
                            binding={binding}
                            factory={factory}
                            starting={starting}
                            synchronize={synchronize}
                        >
                            {(data) => render(index + 1, { ...effective, [prop]: data })}
                        </InputSubscription>
                    );
                }
                return render(0, ordinary);
            }
            // Preserve Inertia persistent layouts and component statics.
            Object.assign(Wrapped, Component);
            wrappers.set(Component, Wrapped);
            return Wrapped;
        },
    };
}
