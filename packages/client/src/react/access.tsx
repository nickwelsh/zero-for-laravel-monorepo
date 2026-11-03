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

/** One revision subscription and coalesced batches per native instance AND actor context. */
function monitor(zero: NativeZero, context: string) {
    const entries = new Map<string, Entry>();
    let running = false;
    let epoch = zero.connection.state.current;
    let revision: string | undefined;
    let revisionStop: (() => void) | undefined;
    let connectionStop: (() => void) | undefined;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let revisionTimer: ReturnType<typeof setTimeout> | undefined;
    let revisionFailures = 0;
    let queued = false;
    // These are genuine named native queries, sent over Zero's existing socket.
    const builder = createBuilder(zero.schema);
    const queries = defineQueries({
        __zero_access_revision: defineQuery((_options: { args: { generation: string }; ctx: ClientContext }) =>
            builder.permission_access_revision!.where('id', 'global'),
        ),
        __zero_access_status: defineQuery(
            ({
                args,
            }: {
                args: {
                    generation: string;
                    bindings: { binding: string; query: string; args: Record<string, ReadonlyJSONValue> }[];
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
    function detach(entry: Entry) {
        const batch = entry.batch;
        entry.batch = undefined;
        if (batch && !batch.entries.some((member) => member.batch === batch)) batch.stop?.();
    }
    function invalidate(entry: Entry) {
        detach(entry);
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
            if (revision !== undefined) next = Math.min(next, entry.due);
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
                        query: entry.binding.query,
                        args: entry.binding.args as Record<string, ReadonlyJSONValue>,
                    })),
                }),
                { ttl: 0 },
            );
            unsubscribe = view.addListener((data, type) => {
                if (!active || !connected()) return;
                const rows = type === 'complete' && Array.isArray(data) ? data.map(record) : [];
                for (const entry of members) {
                    if (entry.batch !== batch) continue;
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
                        // Losing an already-confirmed row, however, invalidates immediately.
                        if (batch.answered.has(entry)) {
                            entry.lease = 0;
                            emit(entry, 'unknown');
                        }
                        if (type === 'error' || type === 'complete') retry(entry);
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
        const due =
            revision === undefined ? [] : [...entries.values()].filter((entry) => entry.due <= performance.now());
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
    function retryRevision(delay?: number) {
        if (!connected() || revisionTimer !== undefined) return;
        revisionTimer = setTimeout(
            () => {
                revisionTimer = undefined;
                watchRevision();
            },
            delay ?? Math.min(1000 * 2 ** Math.min(revisionFailures++, 4), renewalMS),
        );
    }
    function watchRevision() {
        revisionStop?.();
        revisionStop = undefined;
        revision = undefined;
        invalidateAll();
        clearTimeout(timer);
        timer = undefined;
        clearTimeout(revisionTimer);
        revisionTimer = undefined;
        if (!connected()) return;
        const observedEpoch = epoch;
        let active = true;
        let attaching = true;
        let view: TypedView<unknown> | undefined;
        let unsubscribe: (() => void) | undefined;
        revisionStop = () => {
            active = false;
            unsubscribe?.();
            view?.destroy();
        };
        try {
            // A new identity forces server confirmation even when the previous
            // revision query is retained as complete on this connection.
            view = zero.materialize(queries['__zero_access_revision']({ generation: crypto.randomUUID() }), { ttl: 0 });
            unsubscribe = view.addListener((data, type) => {
                if (!active || !connected() || epoch !== observedEpoch) return;
                // addListener synchronously replays a retained snapshot. It cannot
                // prove freshness for this connection epoch, even if 'complete'.
                if (attaching) return;
                const row = Array.isArray(data) && data.length === 1 ? record(data[0]) : undefined;
                const next =
                    type === 'complete' && row?.id === 'global' && text(row.revision) ? row.revision : undefined;
                if (next === undefined) retryRevision();
                else {
                    clearTimeout(revisionTimer);
                    revisionTimer = undefined;
                    revisionFailures = 0;
                }
                if (next === revision) return;
                revision = next;
                invalidateAll();
                queue();
            });
        } catch {
            revisionStop();
            retryRevision();
        } finally {
            attaching = false;
            // No new completion is not evidence of freshness. Retry at a
            // bounded rate rather than trusting a retained complete replay.
            if (revision === undefined) retryRevision(renewalMS);
        }
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
            watchRevision();
        });
        document.addEventListener('visibilitychange', visibility);
        watchRevision();
    }
    function stop() {
        running = false;
        revisionStop?.();
        revisionStop = undefined;
        connectionStop?.();
        connectionStop = undefined;
        document.removeEventListener('visibilitychange', visibility);
        clearTimeout(timer);
        timer = undefined;
        clearTimeout(revisionTimer);
        revisionTimer = undefined;
        invalidateAll();
    }
    return {
        entry(binding: AccessBinding) {
            const key = selectionKey([binding.query, binding.args]);
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
                        entries.delete(key);
                        schedule();
                        // React replaces subscriptions within one commit when
                        // editable args change. Keep the revision view through
                        // that handoff, but never retain the old binding's lease.
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

/** Unmounts protected content before invoking the optional confirmed-loss callback. */
export function AccessBoundary({
    binding,
    prop,
    options,
    children,
}: {
    binding: AccessBinding;
    prop: string;
    options: AccessOptions;
    children: ReactNode;
}) {
    const zero = useZero<Schema, undefined, ClientContext>();
    const context = selectionKey(zero.context);
    const key = selectionKey(binding);
    const store = useMemo(() => {
        let contexts = groups.get(zero);
        if (!contexts) groups.set(zero, (contexts = new Map()));
        let group = contexts.get(context);
        if (!group) contexts.set(context, (group = monitor(zero, context)));
        return group.entry(binding);
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps -- Serialized binding/context identity.
    }, [zero, context, key]);
    const state = useSyncExternalStore(store.subscribe, store.snapshot, store.initial);
    const notified = useRef({ store, count: 0 });
    useEffect(() => {
        if (notified.current.store !== store) notified.current = { store, count: 0 };
        if (state.revocation > notified.current.count) {
            notified.current.count = state.revocation;
            options.onRevoked?.({ status: state.status, query: binding.query, prop });
        }
    }, [state, options, binding.query, prop, store]);
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
