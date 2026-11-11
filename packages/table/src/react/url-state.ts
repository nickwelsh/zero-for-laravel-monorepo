export interface TableURLAdapter {
    /** Current URL, including any writes not yet committed by an asynchronous router. Must be SSR-safe. */
    getURL(): string;
    subscribe(listener: () => void): () => void;
    navigate(url: string, history: 'replace' | 'push'): void;
}

export interface TableURLStateOptions {
    /** Defaults to table.<descriptor name>. Use distinct keys for independent instances of the same table. */
    key?: string;
    /** Defaults to replace, so typing does not create an entry per keystroke. */
    history?: 'replace' | 'push';
    /** The default uses the browser History API. Router integrations supply their own adapter. */
    adapter?: TableURLAdapter;
}

const changed = 'zero-table:url-state';
const browserAdapter: TableURLAdapter = {
    getURL: () => (typeof window === 'undefined' ? '/' : window.location.href),
    subscribe(listener) {
        window.addEventListener('popstate', listener);
        window.addEventListener(changed, listener);
        return () => {
            window.removeEventListener('popstate', listener);
            window.removeEventListener(changed, listener);
        };
    },
    navigate(url, history) {
        window.history[history === 'push' ? 'pushState' : 'replaceState'](window.history.state, '', url);
        window.dispatchEvent(new Event(changed));
    },
};

export function tableURLState(value: boolean | TableURLStateOptions | undefined) {
    const options = typeof value === 'object' ? value : {};
    return { ...options, adapter: options.adapter ?? browserAdapter };
}
