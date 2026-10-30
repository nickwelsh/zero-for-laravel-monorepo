import { router } from '@inertiajs/react';
import { createContext, createElement, useContext, type ComponentType, type ReactNode } from 'react';
import { object } from '../validation';
import { useData, type Data, type ReadSelection } from './data';
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
export function createInertiaAdapter(factories: BindingFactories) {
    let current: TransportPage | undefined;
    const wrappers = new WeakMap<object, unknown>();
    const dispose =
        typeof window === 'undefined'
            ? []
            : [
                  router.on('beforeUpdate', ({ detail }) => normalizePage(detail.page, current)),
                  router.on('navigate', ({ detail }) => {
                      current = detail.page;
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
                    const selection = factory(binding.args);
                    if (starting) return render(index + 1, { ...effective, [prop]: loading(selection) });
                    return (
                        <Subscription key={prop} selection={selection}>
                            {(data) => render(index + 1, { ...effective, [prop]: data })}
                        </Subscription>
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
