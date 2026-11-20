import {
    createBuilder,
    defineQueries,
    defineQuery,
    type Zero,
    type Schema,
    type TypedView,
    type ReadonlyJSONValue,
} from '@rocicorp/zero';
import { useZero } from '@rocicorp/zero/react';
import { createContext, useEffect, useMemo, useRef, useSyncExternalStore, type ReactNode } from 'react';
import type { ClientContext } from '../contract';
import { selectionKey } from '../validation';

export interface AccessContract {
    status: 403 | 404;
    scope?: 'page';
    revoked?: { route: string; message?: string };
}
export type AccessBinding = {
    access?: AccessContract;
} & (
    | { query: string; args: Record<string, unknown>; permission?: never }
    | { permission: { model: string; ability: string; id?: string }; query?: never; args?: never }
);
export interface AccessLost {
    status: 403 | 404;
    query: string;
    prop: string;
    message?: string;
}
export interface AccessOptions {
    onRevoked?: (event: AccessLost) => void;
    onError?: (status: 403 | 404) => ReactNode;
    onPending?: () => ReactNode;
}

/** A new native business-query identity, not an authorization credential. */
export const AccessGeneration = createContext<string | undefined>(undefined);

interface Snapshot {
    state: 'allowed' | 'denied' | 'unknown';
    status: 403 | 404;
    revocation: number;
    generation: string;
}
interface Entry {
    binding: AccessBinding;
    id: string;
    snapshot: Snapshot;
    pending: Snapshot;
    listeners: Set<() => void>;
    previouslyAllowed: boolean;
    lease: number;
    due: number;
    failures: number;
    batch?: Batch | undefined;
    previous?: Batch | undefined;
}
interface Batch {
    generation: string;
    start: number;
    entries: Entry[];
    answered: Set<Entry>;
    stop?: (() => void) | undefined;
}
type NativeZero = Zero<Schema, undefined, ClientContext>;
const leaseMS = 20_000;
const renewalMS = 15_000;
const groups = new WeakMap<NativeZero, Map<string, ReturnType<typeof monitor>>>();

function record(value: unknown): Record<string, unknown> | undefined {
    return value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : undefined;
}
function text(value: unknown): value is string {
    return typeof value === 'string' && value.length > 0 && value.length <= 256;
}

/** Coalesced checks per native instance AND actor context; no broadcast subscription. */
function monitor(zero: NativeZero, context: string) {
    const entries = new Map<string, Entry>();
    let running = false;
    let epoch = zero.connection.state.current;
    let connectionStop: (() => void) | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let queued = false;
    // These are genuine named native queries, sent over Zero's existing socket.
    const builder = createBuilder(zero.schema);
    const queries = defineQueries({
        __zero_access_status: defineQuery(
            ({
                args,
            }: {
                args: {
                    generation: string;
                    bindings: ({ binding: string } & (
                        | { query: string; args: Record<string, ReadonlyJSONValue> }
                        | { permission: { model: string; ability: string; id?: string } }
                    ))[];
                };
                ctx: ClientContext;
            }) => builder.permission_access_results!.where('generation', args.generation),
        ),
    });
    const current = () => running && epoch === zero.connection.state.current && selectionKey(zero.context) === context;
    const connected = () => current() && epoch.name === 'connected';
    function emit(entry: Entry, state: Snapshot['state'], status = entry.snapshot.status) {
        const before = entry.snapshot;
        const revocation = before.revocation + (state === 'denied' && entry.previouslyAllowed ? 1 : 0);
        if (state === 'denied') entry.previouslyAllowed = false;
        if (state === 'allowed') entry.previouslyAllowed = true;
        // Renewal alone must not remount business queries (or nested boundaries).
        const generation = state === before.state ? before.generation : crypto.randomUUID();
        if (before.state === state && before.status === status && before.revocation === revocation) return;
        entry.snapshot = { state, status, revocation, generation };
        entry.pending = { ...entry.snapshot, state: 'unknown' };
        entry.listeners.forEach((listener) => listener());
    }
    function detach(entry: Entry, slot: 'batch' | 'previous' = 'batch') {
        const batch = entry[slot];
        entry[slot] = undefined;
        if (batch && !batch.entries.some((member) => member.batch === batch || member.previous === batch))
            batch.stop?.();
    }
    function invalidate(entry: Entry) {
        detach(entry);
        detach(entry, 'previous');
        entry.lease = 0;
        entry.due = 0;
        emit(entry, 'unknown');
    }
    function invalidateAll() {
        for (const entry of entries.values()) invalidate(entry);
    }
    function retry(entry: Entry) {
        entry.due = Math.min(
            entry.due,
            performance.now() + Math.min(1000 * 2 ** Math.min(entry.failures++, 4), renewalMS),
        );
    }
    function schedule() {
        clearTimeout(timer);
        timer = undefined;
        if (!connected()) return;
        const now = performance.now();
        let next = Infinity;
        for (const entry of entries.values()) {
            if (entry.lease && entry.lease <= now) {
                entry.lease = 0;
                emit(entry, 'unknown');
            }
            if (entry.lease) next = Math.min(next, entry.lease);
            next = Math.min(next, entry.due);
        }
        if (Number.isFinite(next)) timer = setTimeout(flush, Math.max(0, next - now));
    }
    function check(members: Entry[]) {
        const batch: Batch = {
            generation: crypto.randomUUID(),
            start: performance.now(),
            entries: members,
            answered: new Set(),
        };
        for (const entry of members) {
            // Overlap class-page watches during renewal. Never keep ordinary
            // props visible on the strength of a detached approval, or unmount
            // the user's form just because an unchanged approval was renewed.
            if (
                entry.binding.access?.scope === 'page' &&
                entry.batch?.answered.has(entry) &&
                entry.lease > batch.start
            ) {
                detach(entry, 'previous');
                entry.previous = entry.batch;
            }
            detach(entry);
            entry.batch = batch;
            entry.due = batch.start + renewalMS;
        }
        let view: TypedView<unknown> | undefined;
        let unsubscribe: (() => void) | undefined;
        let active = true;
        batch.stop = () => {
            active = false;
            unsubscribe?.();
            view?.destroy();
        };
        try {
            view = zero.materialize(
                queries['__zero_access_status']({
                    generation: batch.generation,
                    bindings: members.map((entry) => ({
                        binding: entry.id,
                        ...(entry.binding.permission
                            ? { permission: entry.binding.permission }
                            : {
                                  query: entry.binding.query,
                                  args: entry.binding.args as Record<string, ReadonlyJSONValue>,
                              }),
                    })),
                }),
                { ttl: 0 },
            );
            unsubscribe = view.addListener((data, type) => {
                if (!active || !connected()) return;
                const rows = type === 'complete' && Array.isArray(data) ? data.map(record) : [];
                for (const entry of members) {
                    if (entry.batch !== batch && entry.previous !== batch) continue;
                    const matching = rows.filter(
                        (row) => row?.generation === batch.generation && row.binding === entry.id,
                    );
                    const row = matching.length === 1 ? matching[0] : undefined;
                    const valid =
                        row &&
                        text(row.id) &&
                        ((row.state === 'allowed' && (row.status === undefined || row.status === null)) ||
                            (row.state === 'denied' && (row.status === 403 || row.status === 404)));
                    if (valid && performance.now() < batch.start + leaseMS) {
                        if (entry.previous === batch) continue; // Observes loss only, never renews the old lease.
                        detach(entry, 'previous');
                        batch.answered.add(entry);
                        entry.lease = batch.start + leaseMS;
                        entry.failures = 0;
                        entry.due = batch.start + renewalMS;
                        emit(
                            entry,
                            row.state as 'allowed' | 'denied',
                            row.state === 'denied' ? (row.status as 403 | 404) : undefined,
                        );
                    } else {
                        // An incomplete renewal may keep an earlier unexpired lease.
                        // A live dependency removed only this binding's result.
                        // Recheck it immediately without touching sibling approvals.
                        if (batch.answered.has(entry)) {
                            invalidate(entry);
                            queue();
                        } else if (type === 'error' || type === 'complete') {
                            if (entry.previous) {
                                detach(entry, 'previous');
                                entry.lease = 0;
                                emit(entry, 'unknown');
                            }
                            retry(entry);
                        }
                    }
                }
                schedule();
            });
        } catch {
            batch.stop();
            for (const entry of members) if (entry.batch === batch) retry(entry);
        }
    }
    function flush() {
        clearTimeout(timer);
        timer = undefined;
        if (!connected()) return;
        const due = [...entries.values()].filter((entry) => entry.due <= performance.now());
        for (let offset = 0; offset < due.length; offset += 32) check(due.slice(offset, offset + 32));
        schedule();
    }
    function queue() {
        if (queued) return;
        queued = true;
        queueMicrotask(() => {
            queued = false;
            if (running) flush();
        });
    }
    function visibility() {
        if (document.visibilityState !== 'visible') return;
        invalidateAll();
        queue();
    }
    function start() {
        running = true;
        epoch = zero.connection.state.current;
        connectionStop = zero.connection.state.subscribe(() => {
            // Observe every synchronous Source epoch, including connected ->
            // disconnected -> connected in a single React batch.
            epoch = zero.connection.state.current;
            invalidateAll();
            queue();
        });
        document.addEventListener('visibilitychange', visibility);
        queue();
    }
    function stop() {
        running = false;
        connectionStop?.();
        connectionStop = undefined;
        document.removeEventListener('visibilitychange', visibility);
        clearTimeout(timer);
        timer = undefined;
        invalidateAll();
    }
    return {
        entry(binding: AccessBinding) {
            const key = selectionKey([binding.query, binding.args, binding.permission]);
            const initial: Snapshot = {
                state: 'unknown',
                status: binding.access!.status,
                revocation: 0,
                generation: crypto.randomUUID(),
            };
            // Do not register a binding until React actually subscribes. Aborted
            // renders must not leave watches or timers behind.
            let entry: Entry | undefined;
            return {
                snapshot: () => {
                    if (!entry) return initial;
                    return connected() && entry.lease > performance.now() ? entry.snapshot : entry.pending;
                },
                initial: () => initial,
                subscribe(this: void, listener: () => void) {
                    entry = entries.get(key);
                    if (!entry) {
                        entry = {
                            binding,
                            id: crypto.randomUUID(),
                            snapshot: initial,
                            pending: initial,
                            listeners: new Set(),
                            previouslyAllowed: false,
                            lease: 0,
                            due: 0,
                            failures: 0,
                        };
                        entries.set(key, entry);
                    }
                    const selected = entry;
                    selected.listeners.add(listener);
                    if (!running) start();
                    // Adding a nested binding checks only that binding; the
                    // parent's live approval and business identity stay intact.
                    queue();
                    return () => {
                        selected.listeners.delete(listener);
                        if (selected.listeners.size) return;
                        detach(selected);
                        detach(selected, 'previous');
                        entries.delete(key);
                        schedule();
                        // React replaces subscriptions within one commit when
                        // editable args change. Keep the monitor through that
                        // handoff, but never retain the old binding's lease.
                        if (!entries.size)
                            queueMicrotask(() => {
                                if (!entries.size) stop();
                            });
                    };
                },
            };
        },
    };
}

const idle: Snapshot = { state: 'unknown', status: 404, revocation: 0, generation: '' };
const idleStore = { snapshot: () => idle, initial: () => idle, subscribe: () => () => {} };

function useAccessState(binding: AccessBinding | null) {
    const zero = useZero<Schema, undefined, ClientContext>();
    const context = selectionKey(zero.context);
    const key = selectionKey(binding);
    const store = useMemo(() => {
        if (!binding) return idleStore;
        let contexts = groups.get(zero);
        if (!contexts) groups.set(zero, (contexts = new Map()));
        let group = contexts.get(context);
        if (!group) contexts.set(context, (group = monitor(zero, context)));
        return group.entry(binding);
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps -- Serialized binding/context identity.
    }, [zero, context, key]);
    const state = useSyncExternalStore(store.subscribe, store.snapshot, store.initial);
    return { store, state };
}

/** Omitted ID checks a class; null waits for an instance identity without subscribing. */
export function usePermission(model: string, ability: string, id?: string | null): boolean | undefined {
    const { state } = useAccessState(
        id === null
            ? null
            : {
                  permission: { model, ability, ...(id === undefined ? {} : { id }) },
                  access: { status: 404 },
              },
    );
    return state.state === 'unknown' ? undefined : state.state === 'allowed';
}

/** Unmounts protected content before invoking the optional confirmed-loss callback. */
export function AccessBoundary({
    binding,
    prop,
    options,
    children,
}: {
    binding: AccessBinding & { query: string };
    prop: string;
    options: AccessOptions;
    children: ReactNode;
}) {
    const { store, state } = useAccessState(binding);
    const notified = useRef({ store, count: 0 });
    useEffect(() => {
        if (notified.current.store !== store) notified.current = { store, count: 0 };
        if (state.state === 'denied' && state.revocation > notified.current.count) {
            notified.current.count = state.revocation;
            options.onRevoked?.({
                status: state.status,
                query: binding.query,
                prop,
                ...(binding.access?.revoked?.message === undefined ? {} : { message: binding.access.revoked.message }),
            });
        }
    }, [state, options, binding, prop, store]);
    if (state.state === 'denied')
        return (
            options.onError?.(state.status) ?? (
                <div role="alert" data-zero-access={state.status}>
                    {state.status === 404 ? 'Not found' : 'Not authorized'}
                </div>
            )
        );
    if (state.state !== 'allowed')
        return options.onPending?.() ?? <output data-zero-access="unknown">Checking access…</output>;
    return <AccessGeneration.Provider value={state.generation}>{children}</AccessGeneration.Provider>;
}
