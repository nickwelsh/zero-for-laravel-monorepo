import { useConnectionState } from '@rocicorp/zero/react';
import { useEffect, useLayoutEffect, useMemo, useRef, useSyncExternalStore, type ReactNode } from 'react';
import { selectionKey } from '../validation';

export interface AccessBinding {
    query: string;
    args: Record<string, unknown>;
    access?: { status: 403 | 404 };
}
export interface AccessLost {
    status: 403 | 404;
    query: string;
    prop: string;
}
export interface AccessOptions {
    endpoint?: string;
    onRevoked?: (event: AccessLost) => void;
    onError?: (status: 403 | 404) => ReactNode;
    onPending?: () => ReactNode;
}
interface Snapshot {
    state: 'allowed' | 'denied' | 'unknown';
    status: 403 | 404;
    revocation: number;
}

function headers(): Record<string, string> {
    const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const cookie = document.cookie
        .split('; ')
        .find((value) => value.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    return {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        ...(token ? { 'X-CSRF-TOKEN': token } : cookie ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) } : {}),
    };
}

type WatchListener = { event: (event: Event) => void; pending: () => void; error: () => void };
const channels = new Map<string, { watches: Map<string, WatchListener>; source?: EventSource; queued: boolean }>();

/** A page's watches share one browser connection and one PHP streaming worker. */
function listen(endpoint: string, id: string, listener: WatchListener): () => void {
    let channel = channels.get(endpoint);
    if (!channel) {
        channel = { watches: new Map(), queued: false };
        channels.set(endpoint, channel);
    }
    const group = channel;
    function reconnect() {
        if (group.queued) return;
        group.queued = true;
        queueMicrotask(() => {
            group.queued = false;
            if (channels.get(endpoint) !== group) return;
            group.source?.close();
            for (const watch of group.watches.values()) watch.pending();
            const source = new EventSource(
                `${endpoint}/events?watches=${encodeURIComponent([...group.watches.keys()].join(','))}`,
                {
                    withCredentials: true,
                },
            );
            group.source = source;
            source.addEventListener('access', (event) => {
                if (group.source === source) for (const watch of group.watches.values()) watch.event(event);
            });
            source.addEventListener('error', () => {
                if (group.source === source) for (const watch of group.watches.values()) watch.error();
            });
        });
    }
    group.watches.set(id, listener);
    reconnect();
    return () => {
        group.watches.delete(id);
        if (group.watches.size === 0) {
            group.source?.close();
            channels.delete(endpoint);
        } else reconnect();
    };
}

/** One route/actor-owned registration; late responses cannot update its successor. */
function monitor(binding: AccessBinding, endpoint: string) {
    const initial: Snapshot = { state: 'unknown', status: binding.access!.status, revocation: 0 };
    let snapshot = initial;
    const listeners = new Set<() => void>();
    let stop: (() => void) | undefined;
    let restart: (() => void) | undefined;
    function publish(state: Snapshot['state'], status = snapshot.status) {
        const revocation = snapshot.revocation + (state === 'denied' && previouslyAllowed ? 1 : 0);
        if (state === 'denied') previouslyAllowed = false;
        if (state === 'allowed') previouslyAllowed = true;
        snapshot = { state, status, revocation };
        listeners.forEach((listener) => listener());
    }
    let previouslyAllowed = false;
    function release(watch: string) {
        void fetch(`${endpoint}/${encodeURIComponent(watch)}`, {
            method: 'DELETE',
            credentials: 'same-origin',
            headers: headers(),
            keepalive: true,
        }).catch(() => {});
    }
    function start() {
        let active = true;
        let disconnect: (() => void) | undefined;
        let id: string | undefined;
        let timer: ReturnType<typeof setTimeout> | undefined;
        const abort = new AbortController();
        const generation = crypto.randomUUID();
        let revision: string | undefined;
        function retry() {
            if (!active) return;
            publish('unknown');
            disconnect?.();
            disconnect = undefined;
            if (id) release(id);
            id = undefined;
            clearTimeout(timer);
            timer = setTimeout(() => {
                void register();
            }, 1000);
        }
        async function register() {
            try {
                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: headers(),
                    signal: abort.signal,
                    body: JSON.stringify({ query: binding.query, args: binding.args, generation }),
                });
                if (response.status === 403 || response.status === 404) {
                    if (active) publish('denied', response.status);
                    return;
                }
                if (!response.ok) {
                    retry();
                    return;
                }
                const result: unknown = await response.json();
                if (!result || typeof result !== 'object' || !('id' in result) || typeof result.id !== 'string') {
                    retry();
                    return;
                }
                if (!active) {
                    release(result.id);
                    return;
                }
                id = result.id;
                revision = undefined;
                // The POST response is not permission to display persisted data.
                // Wait for the stream's authoritative snapshot after registration.
                disconnect = listen(endpoint, id, {
                    pending: () => {
                        revision = undefined;
                        publish('unknown');
                    },
                    error: retry,
                    event: (event) => {
                        if (!active) return;
                        try {
                            const message: unknown = JSON.parse((event as MessageEvent<string>).data);
                            if (
                                !message ||
                                typeof message !== 'object' ||
                                !('id' in message) ||
                                message.id !== id ||
                                !('generation' in message) ||
                                message.generation !== generation
                            )
                                return;
                            if (
                                !('revision' in message) ||
                                typeof message.revision !== 'string' ||
                                message.revision.length === 0 ||
                                message.revision.length > 128 ||
                                !('state' in message)
                            ) {
                                publish('unknown');
                                return;
                            }
                            // SSE orders messages within this connection. Revisions are
                            // opaque model/log fingerprints, not sortable integers.
                            if (
                                message.revision === revision &&
                                message.state === snapshot.state &&
                                (message.state !== 'denied' ||
                                    ('status' in message && message.status === snapshot.status))
                            )
                                return;
                            revision = message.revision;
                            if (message.state === 'allowed') publish('allowed');
                            else if (
                                message.state === 'denied' &&
                                'status' in message &&
                                (message.status === 403 || message.status === 404)
                            )
                                publish('denied', message.status);
                            else publish('unknown');
                        } catch {
                            retry();
                        }
                    },
                });
            } catch {
                if (active) retry();
            }
        }
        restart = retry;
        void register();
        return () => {
            active = false;
            abort.abort();
            clearTimeout(timer);
            disconnect?.();
            if (id) release(id);
            restart = undefined;
        };
    }
    return {
        snapshot: () => snapshot,
        initial: () => initial,
        refresh: () => restart?.(),
        subscribe: (listener: () => void) => {
            listeners.add(listener);
            if (listeners.size === 1) stop = start();
            return () => {
                listeners.delete(listener);
                if (listeners.size === 0) {
                    stop?.();
                    snapshot = { ...snapshot, state: 'unknown' };
                }
            };
        },
    };
}

/** Unmounts protected content before invoking the optional loss callback. */
export function AccessBoundary({
    binding,
    prop,
    options,
    unavailable,
    deniedStatus,
    children,
}: {
    binding: AccessBinding;
    prop: string;
    options: AccessOptions;
    unavailable: boolean;
    deniedStatus?: 403 | 404 | undefined;
    children: ReactNode;
}) {
    const connection = useConnectionState();
    const key = selectionKey(binding);
    const endpoint = options.endpoint ?? '/zero/access';
    // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps -- Identity follows serialized server arguments, never an inline object's reference.
    const store = useMemo(() => monitor(binding, endpoint), [key, endpoint]);
    const state = useSyncExternalStore(store.subscribe, store.snapshot, store.initial);
    const previousConnection = useRef(connection.name);
    useLayoutEffect(() => {
        if (previousConnection.current !== connection.name) {
            previousConnection.current = connection.name;
            store.refresh();
        }
    }, [connection.name, store]);
    const notified = useRef({ store, count: 0 });
    useEffect(() => {
        if (notified.current.store !== store) notified.current = { store, count: 0 };
        if (state.revocation > notified.current.count) {
            notified.current.count = state.revocation;
            options.onRevoked?.({ status: state.status, query: binding.query, prop });
        }
    }, [state, options, binding.query, prop, store]);
    const status = deniedStatus ?? (state.state === 'denied' ? state.status : undefined);
    if (status)
        return (
            options.onError?.(status) ?? (
                <div role="alert" data-zero-access={status}>
                    {status === 404 ? 'Not found' : 'Not authorized'}
                </div>
            )
        );
    if (state.state !== 'allowed' || connection.name !== 'connected' || unavailable)
        return options.onPending?.() ?? <output data-zero-access="unknown">Checking access…</output>;
    return children;
}
