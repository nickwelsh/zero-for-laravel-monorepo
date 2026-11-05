/* oxlint-disable unicorn/no-array-sort -- ES2022 support; only newly allocated arrays are sorted. */
/* oxlint-disable react/refs -- TanStack exposes mutable measurement/prop getters; this hook and its renderer opt out of compilation. */
import { defaultRangeExtractor, useVirtualizer, useWindowVirtualizer, type Range } from '@tanstack/react-virtual';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    useSyncExternalStore,
    type HTMLAttributes,
    type ButtonHTMLAttributes,
    type RefObject,
    type ReactNode,
    type KeyboardEvent,
} from 'react';
import type { Capabilities, TableItem } from '../index';
import type { TableSession } from './index';

export const SessionContext = createContext<unknown>(undefined);
export function useSession<Row, C extends Capabilities>(provided?: TableSession<Row, C>): TableSession<Row, C> {
    const context = useContext(SessionContext) as TableSession<Row, C> | undefined;
    const table = provided ?? context;
    if (!table) throw new Error('Table components require DataTable.Root or an explicit table session.');
    return table;
}
export interface ViewportOptions {
    scrollElement: 'window' | RefObject<HTMLElement | null>;
    estimateSize?: number;
    overscan?: number;
    /** Load ahead of the visible range without rendering extra rows. Defaults to 40. */
    prefetchRows?: number;
}
export function useTableViewport<Row, C extends Capabilities>(
    table: TableSession<Row, C>,
    { scrollElement, estimateSize = 52, overscan = 6, prefetchRows = 40 }: ViewportOptions,
) {
    'use no memo';
    if (!Number.isSafeInteger(prefetchRows) || prefetchRows < 0)
        throw new RangeError('prefetchRows must be a non-negative integer.');
    const revision = useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    const container = useRef<HTMLDivElement>(null);
    const [scrollMargin, setScrollMargin] = useState(0);
    const items = table.items;
    useLayoutEffect(() => {
        const measure = () => {
            if (!container.current) return;
            const top = container.current.getBoundingClientRect().top;
            const panel = scrollElement === 'window' ? null : scrollElement.current;
            setScrollMargin(
                scrollElement === 'window'
                    ? top + window.scrollY
                    : panel
                      ? top - panel.getBoundingClientRect().top - panel.clientTop + panel.scrollTop
                      : 0,
            );
        };
        measure();
        const observer = new ResizeObserver(measure);
        observer.observe(document.body);
        if (container.current?.parentElement) observer.observe(container.current.parentElement);
        window.addEventListener('resize', measure);
        return () => {
            observer.disconnect();
            window.removeEventListener('resize', measure);
        };
    }, [scrollElement]);
    const getItemKey = useCallback((index: number) => items[index]!.id, [items]);
    const pinned = items.flatMap((item, index) =>
        item.id === table.focusedItem || item.id === table.editingRowId ? [index] : [],
    );
    const pinnedKey = pinned.join(',');
    const rangeExtractor = useCallback(
        (range: Range) => [...new Set([...defaultRangeExtractor(range), ...pinned])].sort((a, b) => a - b),
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps -- Index identity, not array identity.
        [pinnedKey],
    );
    const options = {
        count: items.length,
        getItemKey,
        estimateSize: () => estimateSize,
        overscan,
        scrollMargin,
        rangeExtractor,
        // Keep the visible keyed row through live prepend/trim and subsequent measurement.
        // Do not follow appends or use snapshot counts to invent virtual positions.
        anchorTo: 'end' as const,
        followOnAppend: false,
        initialOffset: () => (scrollElement === 'window' ? window.scrollY : (scrollElement.current?.scrollTop ?? 0)),
    };
    // oxlint-disable-next-line react/incompatible-library -- Mutable virtualizer snapshots are explicitly outside React compilation.
    const panel = useVirtualizer<HTMLDivElement, HTMLDivElement>({
        ...options,
        getScrollElement: () => (scrollElement === 'window' ? null : (scrollElement.current as HTMLDivElement | null)),
        enabled: scrollElement !== 'window',
    });
    const page = useWindowVirtualizer<HTMLDivElement>({ ...options, enabled: scrollElement === 'window' });
    const virtualizer = scrollElement === 'window' ? page : panel;
    const virtualItems = virtualizer.getVirtualItems();
    // Network lookahead follows the actual viewport, not DOM overscan or a distant
    // row pinned for editing/focus. Group directories and live prefixes share it.
    const range = virtualizer.range;
    const nearby = range
        ? items.slice(Math.max(0, range.startIndex - prefetchRows), range.endIndex + prefetchRows + 1)
        : [];
    const nearbyKey = nearby.map((item) => item.id).join('\n');
    useEffect(() => {
        table.setViewport(nearby.map((item) => item.id));
        for (const item of nearby) if (item.kind === 'load-more') table.loadMore(item.id);
        // Canonical IDs and session revisions carry demand; array object identity does not.
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
    }, [table, nearbyKey, revision]);
    const focus = (index: number, column = 0) => {
        const item = items[index];
        if (!item) return;
        table.focusItem(item.id);
        virtualizer.scrollToIndex(index, { align: 'auto' });
        requestAnimationFrame(() => {
            const row = container.current?.querySelector<HTMLElement>(`[data-index="${index}"]`);
            const cells = row?.querySelectorAll<HTMLElement>('[role="gridcell"]');
            (cells?.[Math.min(column, cells.length - 1)] ?? row?.querySelector<HTMLElement>('button') ?? row)?.focus({
                preventScroll: true,
            });
        });
    };
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (
            event.defaultPrevented ||
            event.nativeEvent.isComposing ||
            (event.target instanceof Element && event.target.closest('input,textarea,select,[contenteditable="true"]'))
        )
            return;
        const row = event.target instanceof Element ? event.target.closest<HTMLElement>('[data-index]') : null;
        const current = row ? Number(row.dataset.index) : (virtualItems[0]?.index ?? -1);
        const item = items[current];
        if (item?.kind === 'group' && (event.key === 'ArrowLeft' || event.key === 'ArrowRight')) {
            event.preventDefault();
            if (event.key === 'ArrowLeft') table.collapse(item.id);
            else table.expand(item.id);
            return;
        }
        const cells = row ? [...row.querySelectorAll<HTMLElement>('[role="gridcell"]')] : [];
        const column = Math.max(0, cells.indexOf(event.target as HTMLElement));
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
            const next = cells[column + (event.key === 'ArrowLeft' ? -1 : 1)];
            if (next) {
                event.preventDefault();
                next.focus();
            }
            return;
        }
        if (!['ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const direction = event.key === 'ArrowUp' || event.key === 'End' ? -1 : 1;
        let next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : current + direction;
        while (next >= 0 && next < items.length && !['row', 'group'].includes(items[next]!.kind)) next += direction;
        if (next >= 0 && next < items.length) focus(next, column);
    };
    return {
        ref: container,
        items: virtualItems,
        totalSize: virtualizer.getTotalSize(),
        scrollMargin,
        measureElement: virtualizer.measureElement,
        getItemProps: (index: number) => {
            const virtual = virtualItems.find((item) => item.index === index)!;
            return {
                'data-index': index,
                'data-table-item': items[index]!.id,
                'data-table-kind': items[index]!.kind,
                ref: virtualizer.measureElement,
                tabIndex: -1,
                style: {
                    position: 'absolute' as const,
                    top: 0,
                    left: 0,
                    width: '100%',
                    transform: `translateY(${virtual.start - scrollMargin}px)`,
                },
            };
        },
        getViewportProps: () => ({
            role: 'grid',
            tabIndex: 0,
            onKeyDown,
            onFocusCapture: (event: React.FocusEvent<HTMLDivElement>) => {
                const row = event.target.closest<HTMLElement>('[data-table-item]');
                if (row) table.focusItem(row.dataset.tableItem);
            },
            onBlurCapture: (event: React.FocusEvent<HTMLDivElement>) => {
                if (!event.currentTarget.contains(event.relatedTarget)) table.focusItem(undefined);
            },
            style: {
                height: virtualizer.getTotalSize(),
                position: 'relative' as const,
                overflowAnchor: 'none' as const,
            },
        }),
        scrollToIndex: (index: number) => virtualizer.scrollToIndex(index, { align: 'auto' }),
    };
}
export function Viewport<Row, C extends Capabilities>({
    table: provided,
    scrollElement,
    children,
    estimateSize,
    overscan,
    prefetchRows,
    ...props
}: Omit<HTMLAttributes<HTMLDivElement>, 'children'> &
    ViewportOptions & { table?: TableSession<Row, C>; children: (item: TableItem<Row>) => ReactNode }) {
    'use no memo';
    const table = useSession(provided);
    const viewport = useTableViewport(table, {
        scrollElement,
        ...(estimateSize === undefined ? {} : { estimateSize }),
        ...(overscan === undefined ? {} : { overscan }),
        ...(prefetchRows === undefined ? {} : { prefetchRows }),
    });
    const behavior = viewport.getViewportProps();
    return (
        <div
            {...props}
            {...behavior}
            role="grid"
            tabIndex={0}
            ref={viewport.ref}
            style={{ ...props.style, ...behavior.style }}
            onKeyDown={(event) => {
                props.onKeyDown?.(event);
                if (!event.defaultPrevented) behavior.onKeyDown(event);
            }}
        >
            {viewport.items.map((item) => (
                <div key={item.key} {...viewport.getItemProps(item.index)}>
                    {children(table.items[item.index]!)}
                </div>
            ))}
        </div>
    );
}
export function Group<Row, C extends Capabilities>({
    table: provided,
    group,
    children,
    ...props
}: ButtonHTMLAttributes<HTMLButtonElement> & {
    table?: TableSession<Row, C>;
    group: Extract<TableItem<Row>, { kind: 'group' }>;
}) {
    const table = useSession(provided);
    const hoverTimer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
    useEffect(() => () => clearTimeout(hoverTimer.current), []);
    return (
        <button
            type="button"
            {...props}
            aria-expanded={group.expanded}
            data-table-group={group.id}
            data-depth={group.depth}
            onClick={(event) => {
                props.onClick?.(event);
                if (!event.defaultPrevented) table.toggle(group.id);
            }}
            onPointerEnter={(event) => {
                props.onPointerEnter?.(event);
                if (!event.defaultPrevented && event.pointerType !== 'touch') {
                    clearTimeout(hoverTimer.current);
                    hoverTimer.current = setTimeout(() => table.warm(group.id), 150);
                }
            }}
            onPointerLeave={(event) => {
                props.onPointerLeave?.(event);
                clearTimeout(hoverTimer.current);
            }}
            onFocus={(event) => {
                props.onFocus?.(event);
                if (!event.defaultPrevented) table.warm(group.id);
            }}
        >
            {children ?? group.label}
        </button>
    );
}
export function Row({
    item,
    ...props
}: HTMLAttributes<HTMLDivElement> & { item: Extract<TableItem<unknown>, { kind: 'row' }> }) {
    // oxlint-disable-next-line jsx-a11y/prefer-tag-over-role -- This is an ARIA grid, not an HTML table.
    return <div role="row" data-table-row={item.id} {...props} />;
}
