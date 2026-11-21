import {
    createElement,
    forwardRef,
    useCallback,
    useEffect,
    useImperativeHandle,
    useLayoutEffect,
    useSyncExternalStore,
    useRef,
    useState,
    type HTMLAttributes,
    type ReactNode,
    type RefObject,
} from 'react';
import { useNamedScroll, type ScrollAnchor, type ScrollHandle } from './scroll-context';

export interface InfiniteScrollSlotProps {
    loading: boolean;
    loadingPrevious: boolean;
    loadingNext: boolean;
}

export interface InfiniteScrollActionSlotProps extends InfiniteScrollSlotProps {
    fetch(this: void): void;
    autoMode: boolean;
    manualMode: boolean;
    hasMore: boolean;
    hasNext: boolean;
    hasPrevious: boolean;
}

export interface InfiniteScrollRef {
    fetchNext(this: void): void;
    fetchPrevious(this: void): void;
    hasNext(this: void): boolean;
    hasPrevious(this: void): boolean;
}

type Target = string | RefObject<HTMLElement | null>;
type Slot<T> = ReactNode | ((props: T) => ReactNode);
type Side = 'next' | 'previous';

export interface InfiniteScrollProps extends Omit<HTMLAttributes<HTMLElement>, 'children'> {
    data: string;
    as?: string;
    buffer?: number;
    manual?: boolean;
    manualAfter?: number;
    preserveUrl?: boolean;
    /** Render one direct child per row in reverse query order; this swaps triggers, not children. */
    reverse?: boolean;
    autoScroll?: boolean;
    onlyNext?: boolean;
    onlyPrevious?: boolean;
    startElement?: Target;
    endElement?: Target;
    itemsElement?: Target;
    children?: Slot<InfiniteScrollSlotProps>;
    previous?: Slot<InfiniteScrollActionSlotProps>;
    next?: Slot<InfiniteScrollActionSlotProps>;
    loading?: Slot<InfiniteScrollActionSlotProps>;
}

function slot<T>(value: Slot<T> | undefined, props: T) {
    return typeof value === 'function' ? value(props) : value;
}

function allowed(handle: ScrollHandle | null, side: Side) {
    return !!handle?.complete && !handle.error && (side === 'next' ? handle.hasNext : handle.hasPrevious);
}

function useElement(target?: Target) {
    const [fallback, attach] = useState<HTMLElement | null>(null);
    const [custom, setCustom] = useState<HTMLElement | null>(null);
    // External refs (including later siblings) are assigned during commit, not render. A stable
    // ref/selector may resolve to a different DOM node on any commit; compare the node itself.
    // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps -- Re-resolve external targets after every commit.
    useEffect(() => {
        if (!target) return;
        const next = typeof target === 'string' ? document.querySelector<HTMLElement>(target) : target.current;
        // oxlint-disable-next-line react/set-state-in-effect -- Synchronize observer ownership with the committed external DOM node.
        if (next !== custom) setCustom(next);
    });
    return [attach, target ? custom : fallback] as const;
}

function constrained(dimension: string) {
    return !!dimension && !['auto', 'none', '0', '0px'].includes(dimension);
}

function scrollParent(items: HTMLElement | null) {
    for (let el = items?.parentElement; el; el = el.parentElement) {
        const css = getComputedStyle(el);
        if (css.display === 'contents') continue;
        const y = css.overflowY || css.overflow;
        const x = css.overflowX || css.overflow;
        if (y === 'scroll' || x === 'scroll') return el;
        if (y !== 'auto' && x !== 'auto') continue;
        // An unconstrained overflow:auto wrapper grows with its rows. It must not hide a
        // scrollable ancestor from the sentinels (especially with custom itemsElement).
        const parent = el.parentElement && getComputedStyle(el.parentElement);
        const flex = parent?.display === 'flex' || parent?.display === 'inline-flex';
        const column = parent?.flexDirection.startsWith('column');
        if (parent?.display === 'grid' || parent?.display === 'inline-grid') return el;
        if (
            y === 'auto' &&
            (el.scrollHeight > el.clientHeight ||
                constrained(css.maxHeight) ||
                constrained(el.style.height) ||
                (flex && column) ||
                x === 'visible' ||
                x === 'clip')
        )
            return el;
        if (
            x === 'auto' &&
            (el.scrollWidth > el.clientWidth ||
                constrained(css.maxWidth) ||
                constrained(el.style.width) ||
                (flex && !column) ||
                y === 'visible' ||
                y === 'clip')
        )
            return el;
    }
    return null;
}

function useScrollParent(items: HTMLElement | null) {
    const subscribe = useCallback(
        (changed: () => void) => {
            const attributes = new MutationObserver(changed);
            const size = typeof ResizeObserver === 'undefined' ? null : new ResizeObserver(changed);
            for (let node = items; node; node = node.parentElement) {
                attributes.observe(node, { attributes: true, attributeFilter: ['class', 'style'] });
                size?.observe(node);
            }
            window.addEventListener('resize', changed);
            return () => {
                attributes.disconnect();
                size?.disconnect();
                window.removeEventListener('resize', changed);
            };
        },
        [items],
    );
    return useSyncExternalStore(
        subscribe,
        () => scrollParent(items),
        () => null,
    );
}

function useTrigger(
    target: HTMLElement | null,
    root: HTMLElement | null,
    buffer: number,
    side: Side,
    enabled: boolean,
    fetch: (side: Side) => void,
) {
    useLayoutEffect(() => {
        if (!target || !enabled || typeof IntersectionObserver === 'undefined') return undefined;
        if (root && !root.contains(target)) {
            const prop = side === 'next' ? 'endElement' : 'startElement';
            throw new Error(
                `Zero InfiniteScroll: place ${prop} inside the scroll container, outside the direct rows of itemsElement.`,
            );
        }
        let active = true;
        const observer = new IntersectionObserver(
            (entries) => {
                if (active && entries.some((entry) => entry.target === target && entry.isIntersecting)) fetch(side);
            },
            { root, rootMargin: `${Math.max(1, buffer)}px` },
        );
        observer.observe(target);
        return () => {
            active = false;
            observer.disconnect();
        };
    }, [target, root, buffer, side, enabled, fetch]);
}

function useViewedPage(
    items: HTMLElement | null,
    enabled: boolean,
    reverse: boolean,
    preserveUrl: boolean,
    current: RefObject<{ handle: ScrollHandle | null; preserveUrl: boolean } | null>,
) {
    const refresh = useRef<((preserve: boolean) => void) | null>(null);
    useLayoutEffect(() => {
        if (!enabled || !items || typeof IntersectionObserver === 'undefined') return undefined;
        let active = true;
        let timer: number | undefined;
        const visible = new Set<Element>();
        const schedule = (preserve = current.current?.preserveUrl ?? false) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => {
                const h = current.current?.handle;
                if (!active || !h?.complete || h.error) return;
                const counts = new Map<number, number>();
                // A record's page is its current position, not the batch it first arrived in.
                Array.from(items.children).forEach((row, index, rows) => {
                    const position = reverse ? rows.length - 1 - index : index;
                    const page = h.firstPage + Math.floor(position / h.size);
                    if (visible.has(row) && page <= h.lastPage) counts.set(page, (counts.get(page) ?? 0) + 1);
                });
                let best: number | undefined;
                let maximum = 0;
                for (const [page, count] of counts) {
                    if (count > maximum) {
                        best = page;
                        maximum = count;
                    }
                }
                if (best !== undefined) h.view(best, preserve);
            }, 250);
        };
        // The viewport root accounts for clipping by *all* scroll ancestors.
        const observer = new IntersectionObserver((entries) => {
            if (!active) return;
            for (const { target, isIntersecting } of entries) {
                if (isIntersecting && target.parentElement === items) visible.add(target);
                else visible.delete(target);
            }
            schedule();
        });
        for (const row of items.children) observer.observe(row);
        const mutations = new MutationObserver((records) => {
            if (!active) return;
            for (const { addedNodes, removedNodes } of records) {
                for (const node of removedNodes)
                    if (node instanceof Element && node.parentElement !== items) {
                        observer.unobserve(node);
                        visible.delete(node);
                    }
                for (const node of addedNodes)
                    if (node instanceof Element && node.parentElement === items) observer.observe(node);
            }
            schedule();
        });
        mutations.observe(items, { childList: true });
        refresh.current = schedule;
        return () => {
            refresh.current = null;
            active = false;
            window.clearTimeout(timer);
            mutations.disconnect();
            observer.disconnect();
        };
    }, [items, enabled, reverse, current]);
    useLayoutEffect(() => {
        refresh.current?.(preserveUrl);
    }, [preserveUrl]);
}

function useInitialBottom(items: HTMLElement | null, root: HTMLElement | null, enabled: boolean) {
    const positioned = useRef(false);
    useLayoutEffect(() => {
        if (!items || !enabled || positioned.current) return undefined;
        const position = () => {
            if (!items.childElementCount) return false;
            if (root) root.scrollTo({ top: root.scrollHeight, behavior: 'instant' });
            else window.scrollTo({ top: document.body.scrollHeight, behavior: 'instant' });
            positioned.current = true;
            return true;
        };
        if (position()) return undefined;
        const mutations = new MutationObserver(() => {
            if (position()) mutations.disconnect();
        });
        mutations.observe(items, { childList: true });
        return () => mutations.disconnect();
    }, [items, root, enabled]);
}

// Rows can disappear while the wider live range is synchronizing. Keep the visible candidates
// in DOM order so the next surviving row can hold the viewport if the first one disappears.
function visibleRows(items: HTMLElement, root: HTMLElement | null, handle: ScrollHandle, reverse: boolean) {
    const bounds = root?.getBoundingClientRect();
    const top = bounds?.top ?? 0;
    return Array.from(items.children).flatMap((row, i, rows) => {
        const rect = row.getBoundingClientRect();
        const ordinal = (handle.firstPage - 1) * handle.size + (reverse ? rows.length - 1 - i : i);
        return rect.bottom > Math.max(top, 0) &&
            rect.top < Math.min(bounds?.bottom ?? Infinity, window.innerHeight) &&
            rect.right > Math.max(bounds?.left ?? 0, 0) &&
            rect.left < Math.min(bounds?.right ?? Infinity, window.innerWidth)
            ? [{ row, marker: { ordinal, offset: rect.top - top, reverse } }]
            : [];
    });
}

function alignRow(row: Element, root: HTMLElement | null, marker: ScrollAnchor) {
    const delta = row.getBoundingClientRect().top - (root?.getBoundingClientRect().top ?? 0) - marker.offset;
    if (!delta) return;
    if (root) root.scrollTop += delta;
    else window.scrollTo({ top: window.scrollY + delta, behavior: 'instant' });
}

function restoreOrdinal(
    items: HTMLElement,
    root: HTMLElement | null,
    marker: ScrollAnchor,
    handle: ScrollHandle | null | undefined,
) {
    if (!handle?.complete || handle.error) return false;
    const position = marker.ordinal - (handle.firstPage - 1) * handle.size;
    if (position < 0 || position >= (handle.lastPage - handle.firstPage + 1) * handle.size) return true;
    const row = items.children[marker.reverse ? items.childElementCount - 1 - position : position];
    if (!row) return false;
    alignRow(row, root, marker);
    return true;
}

// Completion and the DOM commit can arrive in either order. Stop watching once the row is
// aligned, on a new command/unmount, or when the reader takes control of the scroll position.
function holdAnchor(items: HTMLElement, root: HTMLElement | null, restore: () => boolean, onIntent?: () => void) {
    const controller = new AbortController();
    const changes = new MutationObserver(check);
    function cancel() {
        controller.abort();
        changes.disconnect();
    }
    function check() {
        if (controller.signal.aborted) return;
        if (!items.isConnected || restore()) cancel();
    }
    const onScroll = () => {
        onIntent?.();
        cancel();
    };
    const target = root ?? window;
    for (const event of ['wheel', 'touchmove', 'scroll'])
        target.addEventListener(event, onScroll, { passive: true, signal: controller.signal });
    return {
        cancel,
        complete() {
            if (controller.signal.aborted) return;
            changes.observe(items, { childList: true });
            check();
        },
    };
}

interface Expansion {
    side: Side;
    target: number;
}

function reached(handle: ScrollHandle | null, target: number) {
    return !!handle?.complete && handle.firstPage <= target && handle.lastPage >= target;
}

const MountedScroll = forwardRef<
    InfiniteScrollRef,
    Omit<InfiniteScrollProps, 'data'> & { handle: ScrollHandle | null }
>(function MountedScroll(
    {
        handle,
        as = 'div',
        buffer = 0,
        manual = false,
        manualAfter = 0,
        preserveUrl = false,
        reverse = false,
        autoScroll,
        onlyNext = false,
        onlyPrevious = false,
        startElement,
        endElement,
        itemsElement,
        children,
        previous,
        next,
        loading,
        ...html
    },
    ref,
) {
    const [startRef, startNode] = useElement(startElement);
    const [endRef, endNode] = useElement(endElement);
    const [itemsRef, items] = useElement(itemsElement);
    const root = useScrollParent(items);
    const [expansion, setExpansion] = useState<Expansion | null>(null);
    const lock = useRef<Expansion | null>(null);
    const anchor = useRef<ReturnType<typeof holdAnchor> | null>(null);
    // Only consume a numeric marker present on mount. An uninterrupted fetch owns a DOM anchor.
    const [resumed] = useState(() => handle?.restoreAnchor);
    const usedMarker = useRef(false);
    const config = { handle, items, root, reverse, preserveUrl };
    const current = useRef<typeof config | null>(null);
    useLayoutEffect(() => {
        current.current = config;
        return () => {
            current.current = null;
        };
    });

    useLayoutEffect(() => {
        if (items && resumed && resumed.reverse === reverse && !usedMarker.current) {
            anchor.current = holdAnchor(
                items,
                root,
                () => {
                    usedMarker.current = restoreOrdinal(items, root, resumed, current.current?.handle);
                    return usedMarker.current;
                },
                () => {
                    usedMarker.current = true;
                },
            );
            if (current.current?.handle?.complete) anchor.current.complete();
        }
        return () => {
            anchor.current?.cancel();
            anchor.current = null;
        };
    }, [items, root, resumed, reverse]);

    const fetch = useCallback((side: Side) => {
        const snapshot = current.current;
        if (!snapshot?.handle || lock.current || !allowed(snapshot.handle, side)) return;
        const h = snapshot.handle;
        const prepending = snapshot.reverse ? side === 'next' : side === 'previous';
        const container = snapshot.items;
        const visible = container ? visibleRows(container, snapshot.root, h, snapshot.reverse) : [];
        const marker = visible[0]?.marker;
        anchor.current?.cancel();
        usedMarker.current = true;
        if (container && prepending && marker) {
            const first = container.firstElementChild;
            anchor.current = holdAnchor(container, snapshot.root, () => {
                if (container.firstElementChild === first) return false;
                const surviving = visible.find(({ row }) => row.parentElement === container);
                if (!surviving) return restoreOrdinal(container, snapshot.root, marker, current.current?.handle);
                alignRow(surviving.row, snapshot.root, surviving.marker);
                return true;
            });
        }
        const request = { side, target: side === 'next' ? h.lastPage + 1 : h.firstPage - 1 };
        lock.current = request;
        setExpansion(request);
        let accepted = false;
        try {
            const result = marker ? h.fetch(side, snapshot.preserveUrl, marker) : h.fetch(side, snapshot.preserveUrl);
            // oxlint-disable-next-line typescript/no-unnecessary-boolean-literal-compare -- Only explicit false declines a command; void callbacks accept it.
            accepted = result !== false;
        } finally {
            if (!accepted) {
                anchor.current?.cancel();
                lock.current = null;
                setExpansion(null);
            }
        }
    }, []);

    // A cache emission (or a rerender of the old, complete window) is not an acknowledgement.
    if (expansion && (handle?.error || reached(handle, expansion.target))) setExpansion(null);
    const pending = expansion?.side;
    useLayoutEffect(() => {
        const request = lock.current;
        if (handle?.error) {
            anchor.current?.cancel();
            usedMarker.current = true;
        } else if (!handle?.complete || (request && !reached(handle, request.target))) return;
        else anchor.current?.complete();
        lock.current = null;
    }, [handle]);

    const explicit = (side: Side, options: unknown[]) => {
        if (options.length) throw new Error('Zero InfiniteScroll fetches do not support Inertia reload options.');
        fetch(side);
    };
    useImperativeHandle(ref, () => ({
        fetchNext: (...options: unknown[]) => explicit('next', options),
        fetchPrevious: (...options: unknown[]) => explicit('previous', options),
        hasNext: () => allowed(current.current?.handle ?? null, 'next'),
        hasPrevious: () => allowed(current.current?.handle ?? null, 'previous'),
    }));

    const manualMode = manual || (manualAfter > 0 && (handle?.count ?? 0) >= manualAfter);
    const automatic = !!items && !manualMode && !expansion;
    useTrigger(startNode, root, buffer, 'previous', automatic && !onlyNext && allowed(handle, 'previous'), fetch);
    useTrigger(endNode, root, buffer, 'next', automatic && !onlyPrevious && allowed(handle, 'next'), fetch);
    const ready = !!handle?.complete && !handle.error;
    useViewedPage(items, ready && !expansion, reverse, preserveUrl, current);
    useInitialBottom(items, root, ready && !resumed && (autoScroll ?? reverse));
    const loadingNext = pending === 'next';
    const loadingPrevious = pending === 'previous';
    const action = (side: Side) => {
        const isNext = side === 'next';
        const autoMode = !manualMode && !(isNext ? onlyPrevious : onlyNext);
        const props = {
            loading: isNext ? loadingNext : loadingPrevious,
            loadingNext,
            loadingPrevious,
            hasNext: allowed(handle, 'next'),
            hasPrevious: allowed(handle, 'previous'),
            hasMore: allowed(handle, side),
            autoMode,
            manualMode: !autoMode,
            fetch: () => fetch(side),
        };
        return slot((isNext ? next : previous) ?? (props.loading ? loading : undefined), props);
    };
    // oxlint-disable-next-line react/refs -- The slot's fetch callback reads the current handle when invoked, not while rendering.
    const previousContent = !startElement && action('previous');
    // oxlint-disable-next-line react/refs -- Same event-only fetch callback as the previous slot.
    const nextContent = !endElement && action('next');
    const start = !startElement && (
        <div key="previous" ref={startRef}>
            {previousContent}
        </div>
    );
    const end = !endElement && (
        <div key="next" ref={endRef}>
            {nextContent}
        </div>
    );
    return (
        <>
            {reverse ? end : start}
            {createElement(
                as,
                { ...html, ref: itemsRef },
                slot(children, { loading: !!pending, loadingNext, loadingPrevious }),
            )}
            {reverse ? start : end}
        </>
    );
});

/** Scroll a named Zero::scroll page binding without issuing Inertia reloads. */
export const InfiniteScroll = forwardRef<InfiniteScrollRef, InfiniteScrollProps>(function InfiniteScroll(
    { data, ...props },
    ref,
) {
    const handle = useNamedScroll(data);
    if ('params' in props) throw new Error("Zero InfiniteScroll does not support 'params' (Inertia reload options).");
    return <MountedScroll key={JSON.stringify([data, handle?.identity])} {...props} handle={handle} ref={ref} />;
});
