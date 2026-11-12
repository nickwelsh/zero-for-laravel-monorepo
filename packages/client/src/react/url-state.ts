import { useSyncExternalStore } from 'react';

export interface URLStateAdapter {
    /** Must synchronously expose navigate's latest pending URL, even before an async router commits. */
    getURL(): string;
    /** Writes preserve debounced drafts; restorations cancel them. Omitted events mean restoration. */
    subscribe(listener: (change?: 'write' | 'restore') => void): () => void;
    navigate(url: string, history: 'replace' | 'push'): void;
}
export interface URLStateCodec<T> {
    read(search: URLSearchParams): T;
    write(search: URLSearchParams, value: T): URLSearchParams;
    reset(search: URLSearchParams): URLSearchParams;
}
type Codecs<S> = { [K in keyof S]: URLStateCodec<S[K]> };

function requestKey(value: string): string {
    const url = new URL(value, 'https://zero.invalid');
    return url.pathname + url.search;
}

/** One coordinator per mounted page. Codecs own validation; adapters own history. */
export function createPageURLState<S extends object>(
    adapter: URLStateAdapter,
    codecs: Codecs<S>,
    initial?: { url: string; state: NoInfer<S> },
) {
    const read = (source: string): S => {
        const search = new URL(source, 'https://zero.invalid').searchParams;
        return Object.fromEntries(
            Object.entries(codecs).map(([key, codec]) => [key, (codec as URLStateCodec<unknown>).read(search)]),
        ) as S;
    };
    const currentURL = () => new URL(adapter.getURL(), 'https://zero.invalid').href;
    let source = currentURL();
    // Inertia history may remount with original server props and a newer URL.
    let state =
        initial && requestKey(initial.url) === requestKey(source) ? structuredClone(initial.state) : read(source);
    let stop: (() => void) | undefined;
    const listeners = new Set<() => void>();
    const timers = new Map<keyof S, ReturnType<typeof setTimeout>>();
    const notify = () => listeners.forEach((listener) => listener());
    const cancel = () => {
        timers.forEach(clearTimeout);
        timers.clear();
    };
    function restore(change?: 'write' | 'restore') {
        const next = currentURL();
        if (next === source && change !== 'restore') return;
        source = next;
        if (change !== 'write') cancel();
        const restored = read(source);
        for (const pending of timers.keys()) restored[pending] = state[pending];
        state = restored;
        notify();
    }
    function write<K extends keyof S>(key: K, value: S[K], history: 'replace' | 'push', reset = false) {
        // Always merge against the adapter's latest pending page URL, never a render's snapshot.
        const url = new URL(adapter.getURL(), 'https://zero.invalid');
        url.search = (
            reset ? codecs[key].reset(url.searchParams) : codecs[key].write(url.searchParams, value)
        ).toString();
        source = url.href;
        adapter.navigate(url.pathname + url.search + url.hash, history);
        source = currentURL();
        const restored = read(source);
        for (const pending of timers.keys()) restored[pending] = state[pending];
        state = restored;
        notify();
    }
    return {
        getSnapshot: () => state,
        subscribe(this: void, listener: () => void) {
            listeners.add(listener);
            if (!stop) {
                stop = adapter.subscribe(restore);
                restore();
            }
            return () => {
                listeners.delete(listener);
                if (!listeners.size) {
                    stop?.();
                    stop = undefined;
                    cancel();
                }
            };
        },
        set<K extends keyof S>(
            key: K,
            value: S[K] | ((previous: S[K]) => S[K]),
            options: { history?: 'replace' | 'push'; debounceMs?: number } = {},
        ) {
            const next = typeof value === 'function' ? (value as (previous: S[K]) => S[K])(state[key]) : value;
            // Validate before changing controlled state or scheduling a write.
            codecs[key].write(new URLSearchParams(), next);
            clearTimeout(timers.get(key));
            timers.delete(key);
            state = { ...state, [key]: next };
            notify();
            if (options.debounceMs) {
                timers.set(
                    key,
                    setTimeout(() => {
                        timers.delete(key);
                        write(key, next, options.history ?? 'replace');
                    }, options.debounceMs),
                );
            } else write(key, next, options.history ?? 'replace');
        },
        reset(key: keyof S, history: 'replace' | 'push' = 'replace') {
            clearTimeout(timers.get(key));
            timers.delete(key);
            write(key, state[key], history, true);
        },
    };
}

export function usePageURLState<S extends object>(page: ReturnType<typeof createPageURLState<S>>) {
    return useSyncExternalStore(page.subscribe, page.getSnapshot, page.getSnapshot);
}
