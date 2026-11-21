import { Link as InertiaLink, router, type InertiaLinkProps } from '@inertiajs/react';
import { ZeroContext } from '@rocicorp/zero/react';
import type { Schema, Zero, QueryRequest, ReadonlyJSONValue } from '@rocicorp/zero';
import { forwardRef, useContext, useEffect, useRef } from 'react';
import type { ClientContext } from '../contract';
import { selectionKey } from '../validation';
import type { ReadSelection } from './data';
import { paginatedArgs } from './pagination-url';
import { StartingZero } from './provider';
import type { AccessContract } from './access';
import { installMutationNavigation } from './mutation-navigation';

export interface PageBinding {
    query: string;
    args: Record<string, unknown>;
    access?: AccessContract;
    /** Client-only shell until authoritative arguments arrive. Never subscribed. */
    pending?: boolean;
    one?: boolean;
    options?: Readonly<Record<string, readonly (string | number)[]>>;
}

type NavigationParameter =
    | { route: string; type: 'string' | 'int' | 'bool'; values?: readonly (string | number)[] }
    | { query: readonly string[] };

/** Generated from Laravel's registered routes, not handwritten URL parsing. */
export interface NavigationRoute {
    pattern: string;
    host: string | null;
    protocol: string | null;
    route: string;
    component: string | null;
    name?: string | null;
    uri?: string;
    domain?: string | null;
    client?: boolean;
    defaults: Readonly<Record<string, string | number>>;
    bindings: Readonly<
        Record<
            string,
            {
                query: string;
                one: boolean;
                access?: AccessContract;
                parameters: Readonly<Record<string, NavigationParameter>> | null;
                options?: PageBinding['options'];
            }
        >
    >;
}

export type ZeroLinkProps = Omit<InertiaLinkProps, 'preload'> & { preload?: boolean };
type Factories = Record<string, (args: never) => ReadSelection>;
type NativeZero = Zero<Schema, undefined, ClientContext>;
type NativeRequest = QueryRequest<string, ReadonlyJSONValue, ReadonlyJSONValue, Schema, unknown, ClientContext>;

function retainedState(page: unknown) {
    if (!page) return false;
    if (typeof page !== 'object' || !('props' in page)) return true; // Includes encrypted entries.
    return ['onceProps', 'rememberedState', 'deferredProps', 'initialDeferredProps'].some(
        (key) => Object.keys(Reflect.get(page, key) ?? {}).length > 0,
    );
}

export function createNavigationLink(
    factories: Factories,
    navigation: readonly NavigationRoute[],
    currentPage: Parameters<typeof installMutationNavigation>[0] = () => undefined,
) {
    const routes = navigation.map((route) => ({
        ...route,
        path: new RegExp(route.pattern),
        hostname: route.host === null ? undefined : new RegExp(route.host),
    }));
    const pools = new WeakMap<NativeZero, Map<string, { owners: Set<object>; stop: () => void }>>();
    const stops = new Set<() => void>();
    let disposed = false;

    // Inertia can restore keys from its boot history even after the active entry changes.
    const restored = typeof window !== 'undefined' && retainedState(window.history.state?.page);

    function destination(props: ZeroLinkProps): Record<string, PageBinding> | undefined {
        if (typeof window === 'undefined' || !props.href) return undefined;
        const pair = typeof props.href === 'object' && !(props.href instanceof URL) ? props.href : undefined;
        if ((pair?.method ?? props.method ?? 'get').toLowerCase() !== 'get') return undefined;
        const url = new URL(pair?.url ?? (props.href as string | URL), window.location.href);
        if (url.origin !== window.location.origin) return undefined;
        const component = props.component ?? pair?.component;
        if (typeof component !== 'string') return undefined;
        const matches = routes.filter(
            (route) =>
                route.path.test(url.pathname) &&
                (!route.hostname || route.hostname.test(url.hostname)) &&
                (!route.protocol || route.protocol === url.protocol),
        );
        // Include non-Zero routes as blockers; never guess between overlapping routes.
        const route = matches.find((candidate) => candidate.component === component);
        if (!route) return undefined;
        const resolved = matches.length === 1 && !Object.keys(props.data ?? {}).length;
        const encoded = { ...route.path.exec(url.pathname)?.groups, ...route.hostname?.exec(url.hostname)?.groups };
        const routeArgs: Record<string, string | number> = { ...route.defaults };
        try {
            for (const [key, value] of Object.entries(encoded)) {
                if (value !== undefined) routeArgs[key] = decodeURIComponent(value);
            }
        } catch {
            return undefined;
        }
        const bindings: Record<string, PageBinding> = {};
        for (const [prop, declaration] of Object.entries(route.bindings)) {
            const binding: PageBinding = {
                query: declaration.query,
                args: {},
                one: declaration.one,
                ...(declaration.access ? { access: declaration.access } : {}),
                ...(declaration.options ? { options: declaration.options } : {}),
                pending: true,
            };
            bindings[prop] = binding;
            if (!resolved || declaration.parameters === null) continue;
            const args: Record<string, unknown> = { __route: route.route, __routeArgs: routeArgs };
            try {
                for (const [name, parameter] of Object.entries(declaration.parameters)) {
                    if ('query' in parameter) {
                        const input: Record<string, string> = {};
                        for (const field of parameter.query) {
                            const values = url.searchParams.getAll(field);
                            if (
                                values.length > 1 ||
                                [...url.searchParams.keys()].some((key) => key.startsWith(`${field}[`))
                            )
                                throw new Error('Ambiguous input');
                            if (values.length) {
                                const value = values[0]!;
                                // Middleware may trim strings or convert empty strings to null.
                                if (!value || value.trim() !== value) throw new Error('Server-normalized input');
                                input[field] = value;
                            }
                        }
                        args[name] = input;
                    } else {
                        const raw = routeArgs[parameter.route];
                        if (raw === undefined || String(raw).includes('/')) throw new Error('Unresolved route input');
                        const value =
                            parameter.type === 'string'
                                ? String(raw)
                                : parameter.type === 'bool'
                                  ? raw !== '' && raw !== '0' && raw !== 0
                                  : /^-?(?:0|[1-9]\d*)$/.test(String(raw)) && Number.isSafeInteger(Number(raw))
                                    ? Number(raw)
                                    : undefined;
                        if (
                            value === undefined ||
                            (parameter.values && !parameter.values.includes(value as string | number))
                        )
                            throw new Error('Invalid route input');
                        args[name] = value;
                    }
                }
                if (!Object.hasOwn(factories, binding.query)) continue;
                const selection = (factories[binding.query] as (args: unknown) => ReadSelection)(args);
                binding.args = selection.pagination ? paginatedArgs(args, selection.pagination, url) : args;
                delete binding.pending;
            } catch {
                // Laravel owns inputs that cannot be resolved unambiguously in the browser.
            }
        }
        return bindings;
    }

    const Link = forwardRef<unknown, ZeroLinkProps>(function ZeroLink({ preload = false, ...props }, ref) {
        const zero = useContext(ZeroContext) as NativeZero | undefined;
        const starting = useContext(StartingZero);
        const identity = zero ? selectionKey(zero.context) : '';
        const owner = useRef({});
        const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
        const latest = useRef(props);
        useEffect(() => {
            latest.current = props;
        });
        useEffect(() => {
            if (!zero || !identity || !preload) return undefined;
            const token = owner.current;
            return () => {
                clearTimeout(timer.current);
                for (const entry of pools.get(zero)?.values() ?? []) {
                    entry.owners.delete(token);
                    if (!entry.owners.size) entry.stop();
                }
            };
        }, [zero, identity, preload]);
        useEffect(() => {
            if (!props.href || !preload || starting) clearTimeout(timer.current);
            return () => clearTimeout(timer.current);
        }, [props.href, preload, starting]);

        function warm() {
            clearTimeout(timer.current);
            if (!preload || !zero || starting) return;
            timer.current = setTimeout(() => {
                if (disposed || selectionKey(zero.context) !== identity) return;
                const pool = pools.get(zero) ?? new Map();
                pools.set(zero, pool);
                for (const binding of Object.values(destination(latest.current) ?? {})) {
                    if (binding.pending) continue;
                    const selection = (factories[binding.query] as (args: unknown) => ReadSelection)(binding.args);
                    const key = identity + selection.key;
                    const existing = pool.get(key);
                    if (existing) {
                        existing.owners.add(owner.current);
                        continue;
                    }
                    // A long sidebar must not retain an unbounded number of speculative queries.
                    if (pool.size >= 32) pool.values().next().value?.stop();
                    try {
                        const request = selection.pagination
                            ? selection.pagination.request(selection.pagination.initial.wire)
                            : selection.cursorFields !== undefined
                              ? selection.page!(undefined).request()
                              : selection.pageSize !== undefined && !selection.one
                                ? selection.limit!(
                                      Math.min(selection.pageSize + 1, selection.maxWindow ?? 10000),
                                  ).request()
                                : selection.request();
                        const handle = zero.preload(request as NativeRequest, { ttl: 0 });
                        const stop = () => {
                            if (pool.get(key)?.stop !== stop) return;
                            clearTimeout(expiry);
                            handle.cleanup();
                            pool.delete(key);
                            stops.delete(stop);
                        };
                        const expiry = setTimeout(stop, 30_000);
                        pool.set(key, { owners: new Set([owner.current]), stop });
                        stops.add(stop);
                        void handle.complete.catch(stop);
                    } catch {
                        // Speculation must never prevent ordinary navigation or surface an unhandled rejection.
                    }
                }
            }, 75);
        }
        function cancel() {
            clearTimeout(timer.current);
        }
        const originalPageProps = props.pageProps;
        return (
            <InertiaLink
                {...props}
                ref={ref}
                pageProps={(current, shared) => {
                    const ordinary =
                        typeof originalPageProps === 'function'
                            ? originalPageProps(current, shared)
                            : (originalPageProps ?? shared);
                    const bindings = destination(props);
                    if (!bindings) return ordinary;
                    const clean = { ...ordinary };
                    for (const prop of Object.keys(bindings)) delete clean[prop];
                    return { ...clean, __zero: { version: 1, mode: 'replace', bindings } };
                }}
                onMouseOverCapture={(event) => {
                    props.onMouseOverCapture?.(event);
                    if (
                        !event.defaultPrevented &&
                        !(event.relatedTarget instanceof Node && event.currentTarget.contains(event.relatedTarget))
                    )
                        warm();
                }}
                onMouseOutCapture={(event) => {
                    props.onMouseOutCapture?.(event);
                    if (!(event.relatedTarget instanceof Node && event.currentTarget.contains(event.relatedTarget)))
                        cancel();
                }}
                onFocusCapture={(event) => {
                    props.onFocusCapture?.(event);
                    if (!event.defaultPrevented) warm();
                }}
                onBlurCapture={(event) => {
                    props.onBlurCapture?.(event);
                    cancel();
                }}
                onFinish={(visit) => {
                    cancel();
                    for (const entry of pools.get(zero!)?.values() ?? []) {
                        entry.owners.delete(owner.current);
                        if (!entry.owners.size) entry.stop();
                    }
                    props.onFinish?.(visit);
                }}
            />
        );
    });
    function namedDestination(name: string, parameters: Record<string, unknown>) {
        const candidates = routes.filter((route) => route.name === name);
        if (disposed || candidates.length !== 1 || typeof window === 'undefined') return undefined;
        const route = candidates[0]!;
        if (route.uri === undefined || route.domain === undefined) return undefined;
        const values = { ...route.defaults, ...parameters };
        function interpolate(template: string, separator: string) {
            return template
                .split(separator)
                .flatMap((part) => {
                    const optional = /^\{([^{}?]+)\?\}$/.exec(part);
                    if (optional && values[optional[1]!] == null) return [];
                    return [
                        part.replace(/\{([^{}?]+)\??\}/g, (_, key: string) => {
                            const value = values[key];
                            if (typeof value !== 'string' && typeof value !== 'number')
                                throw new Error('Missing revocation route parameter.');
                            return encodeURIComponent(String(value));
                        }),
                    ];
                })
                .join(separator);
        }
        let url: URL;
        try {
            const host = route.domain === null ? window.location.host : interpolate(route.domain, '.');
            url = new URL(`${route.protocol ?? window.location.protocol}//${host}/${interpolate(route.uri, '/')}`);
            if (!route.path.test(url.pathname) || (route.hostname && !route.hostname.test(url.hostname)))
                return undefined;
        } catch {
            return undefined;
        }
        return { route, url };
    }
    function resolve(name: string, parameters: Record<string, unknown>) {
        const next = namedDestination(name, parameters);
        if (!next) return undefined;
        return {
            ...next,
            bindings: next.route.component
                ? destination({ href: next.url.href, component: next.route.component })
                : undefined,
        };
    }
    const uninstall = installMutationNavigation(currentPage, resolve);
    return {
        Link,
        redirect(name: string, parameters: Record<string, unknown>, internal = false): (() => void) | undefined {
            const next = resolve(name, parameters);
            if (!next) return undefined;
            const source = currentPage();
            const { route, url, bindings } = next;
            if (
                internal &&
                !restored &&
                source &&
                !retainedState(source) &&
                !retainedState(window.history.state?.page) &&
                route.client &&
                route.component &&
                bindings &&
                Object.values(bindings).length > 0 &&
                Object.values(bindings).every((b) => b.access && !b.pending)
            ) {
                // Only destination bindings and explicitly shared props cross the swap.
                const shared = Object.fromEntries(
                    (source.sharedProps ?? [])
                        .filter((k) => k !== '__zero' && k !== 'errors' && !Object.hasOwn(bindings, k))
                        .map((k) => [k, source.props[k]]),
                );
                router.cancelAll();
                router.flushAll();
                router.replace({
                    component: route.component,
                    url: url.pathname + url.search + url.hash,
                    props: { ...shared, errors: {}, __zero: { version: 1, mode: 'replace', bindings } },
                });
                return undefined;
            }
            // Even an Inertia HTTP visit can restore private remembered state
            // from its initial history snapshot. Start a fresh document instead,
            // without leaving an Inertia response that can outlive this page.
            window.location.replace(next.url.href);
            // A document response can also arrive after an in-app departure.
            // Its committed page owner must stop that load before releasing it.
            return () => window.stop();
        },
        dispose: () => {
            disposed = true;
            uninstall();
            for (const stop of stops) stop();
        },
    };
}
