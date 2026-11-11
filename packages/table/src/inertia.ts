import { router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import { useDataTable as useReactDataTable, type TableOptions, type TableURLAdapter } from './react';
import type { Capabilities, TableDescriptor } from './index';
export * from './react';

// Shared across table instances: a second table must preserve the first table's
// pending URL writes, even before Inertia commits them to browser history.
let pendingURL: URL | undefined;
const pendingURLs = new Set<string>();
const listeners = new Set<() => void>();
const inertiaAdapter: TableURLAdapter = {
    getURL: () => pendingURL?.href ?? window.location.href,
    navigate(url, history) {
        pendingURL = new URL(url, window.location.href);
        pendingURLs.add(pendingURL.href);
        router[history]({ url, preserveState: true, preserveScroll: true });
        listeners.forEach((listener) => listener());
    },
    subscribe(listener) {
        const notify = ({ detail }: { detail: { page: { url: string } } }) => {
            const url = new URL(detail.page.url, window.location.href).href;
            if (pendingURLs.has(url) && pendingURL?.href !== url) return;
            pendingURL = undefined;
            pendingURLs.clear();
            listeners.forEach((callback) => callback());
        };
        // Only the first subscriber owns router events; all tables see the same
        // effective URL, rather than consuming each other's pending writes.
        if (!listeners.size) {
            unsubscribe = [router.on('navigate', notify), router.on('clientVisit', notify)];
        }
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
            if (!listeners.size) {
                unsubscribe.forEach((stop) => stop());
                unsubscribe = [];
                pendingURL = undefined;
                pendingURLs.clear();
            }
        };
    },
};
let unsubscribe: (() => void)[] = [];

/** useDataTable with URL state integrated into Inertia's client-side page/history lifecycle. */
export function useDataTable<Row, C extends Capabilities>(
    descriptor: TableDescriptor<Row, C>,
    options: TableOptions<NoInfer<C>, NoInfer<Row>> = {},
) {
    const { url } = usePage();
    const adapter = useMemo(
        () => (typeof window === 'undefined' ? { ...inertiaAdapter, getURL: () => url } : inertiaAdapter),
        [url],
    );
    return useReactDataTable(
        descriptor,
        options.urlState
            ? {
                  ...options,
                  urlState: {
                      adapter,
                      ...(typeof options.urlState === 'object' ? options.urlState : {}),
                  },
              }
            : options,
    );
}
