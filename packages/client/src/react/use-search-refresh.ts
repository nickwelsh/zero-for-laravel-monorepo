import { useEffect } from 'react';

/** Fetch results only after completed indexing, or when returning to the window. */
export function useSearchRefresh(endpoint: string | undefined, index: string, refresh: () => void) {
    useEffect(() => {
        if (endpoint === undefined) {
            return undefined;
        }
        const revisionURL = endpoint;
        const controller = new AbortController();
        let revision: string | undefined;
        let pending = false;
        const active = () => !controller.signal.aborted && !document.hidden;
        async function check() {
            if (!active() || pending) {
                return;
            }
            pending = true;
            try {
                const url = new URL(revisionURL, globalThis.location.origin);
                url.searchParams.set('index', index);
                const response = await fetch(url, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok) {
                    return;
                }
                const result: unknown = await response.json();
                if (
                    active() &&
                    typeof result === 'object' &&
                    result !== null &&
                    'revision' in result &&
                    typeof result.revision === 'string' &&
                    result.revision !== revision
                ) {
                    revision = result.revision;
                    refresh();
                }
            } catch {
                // A failed poll must not hide existing results. The next poll retries.
            } finally {
                pending = false;
            }
        }
        function checkRevision() {
            void check();
        }
        function focus() {
            if (active()) {
                refresh();
                checkRevision();
            }
        }
        const timer = setInterval(checkRevision, 5000);
        globalThis.addEventListener('focus', focus);
        document.addEventListener('visibilitychange', checkRevision);
        checkRevision();
        return () => {
            controller.abort();
            clearInterval(timer);
            globalThis.removeEventListener('focus', focus);
            document.removeEventListener('visibilitychange', checkRevision);
        };
    }, [endpoint, index, refresh]);
}
