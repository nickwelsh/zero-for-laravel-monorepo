import type { URLStateAdapter, URLStateCodec } from '@nickwelsh/zero-for-laravel/react';
import { readTableState, writeTableState, type Capabilities, type TableState } from '../index';
export type TableURLAdapter = URLStateAdapter;

export function tableStateCodec<C extends Capabilities>(
    capabilities: C,
    options: { key?: string; initialState?: Partial<TableState<C>> } = {},
): URLStateCodec<TableState<C>> {
    return {
        read: (search) => readTableState(capabilities, search, options),
        write: (search, state) => writeTableState(capabilities, search, state, options),
        reset(search) {
            const next = new URLSearchParams(search);
            const key = options.key ?? 'table.' + capabilities.name;
            // oxlint-disable-next-line unicorn/no-useless-spread -- Deleting during live iteration skips adjacent keys.
            for (const name of [...next.keys()]) if (name === key || name.startsWith(key + '[')) next.delete(name);
            return next;
        },
    };
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
export const browserURLAdapter: TableURLAdapter = {
    getURL: () => (typeof window === 'undefined' ? '/' : window.location.href),
    subscribe(listener) {
        const restore = () => listener('restore');
        const write = () => listener('write');
        window.addEventListener('popstate', restore);
        window.addEventListener(changed, write);
        return () => {
            window.removeEventListener('popstate', restore);
            window.removeEventListener(changed, write);
        };
    },
    navigate(url, history) {
        window.history[history === 'push' ? 'pushState' : 'replaceState'](window.history.state, '', url);
        window.dispatchEvent(new Event(changed));
    },
};

export function tableURLState(value: boolean | TableURLStateOptions | undefined) {
    const options = typeof value === 'object' ? value : {};
    return { ...options, adapter: options.adapter ?? browserURLAdapter };
}
