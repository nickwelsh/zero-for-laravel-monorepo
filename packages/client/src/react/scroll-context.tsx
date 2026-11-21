import { createContext, useContext, useEffect, type ReactNode } from 'react';
import { scrollPosition, type LivePagination, type PagePosition } from '../pagination';
import type { Data } from './data';

/** Stored alongside the binding in Inertia history, never sent as query input. */
export interface ScrollHistory {
    viewed: number;
    urlPage: number;
    count: number;
}

export interface ScrollHandle {
    identity: string;
    firstPage: number;
    lastPage: number;
    viewedPage: number;
    size: number;
    count: number;
    hasNext: boolean;
    hasPrevious: boolean;
    complete: boolean;
    error: Error | undefined;
    fetch(side: 'next' | 'previous', preserveUrl: boolean): boolean;
    view(page: number, preserveUrl: boolean): void;
}

const ScrollBindings = createContext<ReadonlyMap<string, ScrollHandle | null>>(new Map());

export function NamedScroll({
    name,
    handle,
    children,
}: {
    name: string;
    handle: ScrollHandle | null;
    children: ReactNode;
}) {
    const bindings = useContext(ScrollBindings);
    return <ScrollBindings.Provider value={new Map(bindings).set(name, handle)}>{children}</ScrollBindings.Provider>;
}

export function useNamedScroll(name: string): ScrollHandle | null {
    const handle = useContext(ScrollBindings).get(name);
    if (handle === undefined)
        throw new Error(`InfiniteScroll data="${name}" requires a named Zero::scroll(...->paginate(...)) page prop.`);
    return handle;
}

export function ScrollWindow({
    name,
    identity,
    pagination,
    data,
    history,
    save,
    children,
}: {
    name: string;
    identity: string;
    pagination: LivePagination;
    data: Data<unknown>;
    history: ScrollHistory | undefined;
    save(this: void, position: PagePosition, history: ScrollHistory, writePage?: number): boolean;
    children: ReactNode;
}) {
    const { initial, size } = pagination;
    const first = initial.wire['__page'] ?? 1;
    const last = initial.page;
    const state = history ?? { viewed: last, urlPage: last, count: last - first };
    const ready = data.complete && data.enabled && !data.error;
    // Each successful expansion adds exactly one page. A failed or partial range is not a success.
    const count = ready ? last - first : state.count;
    useEffect(() => {
        if (history && count !== history.count) save(initial.wire, { ...history, count });
    }, [count, history, initial, save]);
    const handle: ScrollHandle = {
        identity,
        firstPage: first,
        lastPage: last,
        viewedPage: state.viewed,
        count,
        size,
        hasNext: ready && data.hasMore === true,
        hasPrevious: ready && first > 1,
        complete: ready,
        error: data.error,
        fetch(side) {
            if (side === 'next' ? !handle.hasNext : !handle.hasPrevious) return false;
            const position = pagination.range(scrollPosition(initial, size, side));
            return save(position.wire, { ...state, count });
        },
        view(page, preserveUrl) {
            if (!ready || !Number.isSafeInteger(page) || page < first || page > last) return;
            const urlPage = preserveUrl ? state.urlPage : page;
            if (page === state.viewed && urlPage === state.urlPage) return;
            save(initial.wire, { viewed: page, urlPage, count }, preserveUrl ? undefined : page);
        },
    };
    return (
        <NamedScroll name={name} handle={handle}>
            {children}
        </NamedScroll>
    );
}
