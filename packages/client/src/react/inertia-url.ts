import { router } from '@inertiajs/react';
import type { URLStateAdapter } from './url-state';

const pendingVisits: { url: URL }[] = [];
const listeners = new Set<(change?: 'write' | 'restore') => void>();
let unsubscribe: (() => void)[] = [];

/** Shared by scalar query bindings, page coordinators and Zero Table. No server visits. */
export const inertiaURLAdapter: URLStateAdapter = {
    getURL: () => pendingVisits.at(-1)?.url.href ?? window.location.href,
    navigate(url, history) {
        inertiaURLVisit({ url, preserveState: true, preserveScroll: true }, history);
    },
    subscribe(listener) {
        const notify = ({ detail, type }: { detail: { page: { url: string }; visitId?: string }; type: string }) => {
            const url = new URL(detail.page.url, window.location.href).href;
            // Push visits emit navigate and clientVisit; only their own onFinish retires them.
            // Back/Forward emits navigate without a visitId, even for an outstanding URL.
            if (detail.visitId && pendingVisits.some((visit) => visit.url.href === url)) return;
            pendingVisits.length = 0;
            listeners.forEach((callback) => callback(type === 'inertia:clientVisit' ? 'write' : 'restore'));
        };
        if (!listeners.size) unsubscribe = [router.on('navigate', notify), router.on('clientVisit', notify)];
        listeners.add(listener);
        return () => {
            listeners.delete(listener);
            if (!listeners.size) {
                unsubscribe.forEach((stop) => stop());
                unsubscribe = [];
                pendingVisits.length = 0;
            }
        };
    },
};

export function inertiaURLVisit(
    visit: Parameters<typeof router.replace<Record<string, unknown>>>[0],
    history: 'replace' | 'push',
) {
    const pending = { url: new URL(visit.url ?? window.location.href, window.location.href) };
    pendingVisits.push(pending);
    router[history]({
        ...visit,
        onFinish(completed) {
            const index = pendingVisits.indexOf(pending);
            if (index !== -1) {
                pendingVisits.splice(index, 1);
                listeners.forEach((listener) => listener('write'));
            }
            visit.onFinish?.(completed);
        },
    });
    listeners.forEach((listener) => listener('write'));
}
