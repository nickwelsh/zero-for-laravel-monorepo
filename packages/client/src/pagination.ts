import type { Column } from './contract';
import { columnValue, fail, object } from './validation';

export interface PaginationDeclaration {
    readonly mode: 'numbered' | 'cursor' | 'scroll';
    readonly name: string;
}

export interface PagePosition {
    readonly __page?: number;
    readonly __window?: number;
    readonly __cursor?: Readonly<Record<string, unknown>>;
    readonly __reverse?: boolean;
}

export function withoutPosition(args: Record<string, unknown>) {
    const { __page: _page, __cursor: _cursor, __reverse: _reverse, __window: _window, ...rest } = args;
    return rest;
}

/** The wire window includes the probe. `start` and `end` address only displayable rows. */
export function livePagination(
    declaration: PaginationDeclaration,
    input: Record<string, unknown>,
    size: number,
    max: number,
    fields: readonly string[],
    columns: Readonly<Record<string, Column>>,
    request: (position: PagePosition) => unknown,
) {
    const { mode, name } = declaration;
    if (!Number.isSafeInteger(size) || size < 1 || size >= max) fail(name, 'Page size exceeds the live window.');
    const lastPage = Math.floor((max - 1) / size);
    const range = (args: Record<string, unknown> | PagePosition) => {
        const { __page: page = 1, __cursor: cursor, __reverse: reverse, __window: window } = args;
        if (typeof page !== 'number' || !Number.isSafeInteger(page) || page < 1 || page > lastPage)
            fail(name, 'Page is outside the live window.');
        if (mode === 'cursor') {
            if (args['__page'] !== undefined || window !== undefined) fail(name, 'Unexpected numbered position.');
            const row = cursor === undefined ? undefined : object(cursor, name);
            if (reverse !== undefined && (!row || typeof reverse !== 'boolean'))
                fail(name, 'Invalid cursor direction.');
            if (row) {
                if (Object.keys(row).length !== fields.length) fail(name, 'Invalid cursor fields.');
                for (const field of fields) {
                    if (!Object.hasOwn(row, field)) fail(name, 'Missing cursor field.');
                    columnValue(row[field], columns[field]!, name);
                }
            }
            const wire: PagePosition = row ? { __cursor: row, ...(reverse ? { __reverse: true } : {}) } : {};
            return { wire, start: 0, end: size, page: 1, capped: false };
        }
        if (cursor !== undefined || reverse !== undefined) fail(name, 'Unexpected cursor position.');
        const minimum = page * size + 1;
        if (mode === 'numbered' && window !== undefined) fail(name, 'Unexpected scroll window.');
        const requested = window === undefined ? minimum : window;
        if (typeof requested !== 'number' || !Number.isSafeInteger(requested) || requested < minimum || requested > max)
            fail(name, 'Scroll window is outside its bounds.');
        const end = requested - 1;
        const wire: PagePosition = {
            ...(page === 1 ? {} : { __page: page }),
            ...(mode === 'scroll' ? { __window: requested } : {}),
        };
        return { wire, start: (page - 1) * size, end, page: Math.ceil(end / size), capped: end + size + 1 > max };
    };
    return { ...declaration, size, range, initial: range(input), request };
}

export type LivePagination = ReturnType<typeof livePagination>;

export function scrollPosition(
    range: LivePagination['initial'],
    size: number,
    side: 'previous' | 'next',
): PagePosition {
    return side === 'next'
        ? { ...range.wire, __window: range.end + size + 1 }
        : { __page: (range.wire['__page'] ?? 1) - 1, __window: range.end + 1 };
}

/** Reverse queries arrive in traversal order; use raw rows, before casts or display reversal. */
export function pageSteps(
    { mode, size }: LivePagination,
    range: LivePagination['initial'],
    rows: readonly unknown[] | undefined,
    cursor: (row: unknown) => Readonly<Record<string, unknown>>,
) {
    let next: PagePosition | undefined;
    let previous: PagePosition | undefined;
    if (mode === 'scroll') {
        if (rows && rows.length > range.end && !range.capped) next = scrollPosition(range, size, 'next');
        if ((range.wire['__page'] ?? 1) > 1) previous = scrollPosition(range, size, 'previous');
    }
    if (mode === 'numbered') {
        if (rows && rows.length > range.end && !range.capped) next = { __page: range.page + 1 };
        if (range.page > 1) previous = { __page: range.page - 1 };
    }
    if (mode === 'cursor' && rows) {
        const reverse = range.wire['__reverse'] === true;
        const probe = rows.length > range.end;
        const anchor = range.wire['__cursor'];
        const last = Math.min(range.end, rows.length) - 1;
        if (reverse ? anchor : probe) {
            const boundary = rows.length ? cursor(rows[reverse ? 0 : last]) : anchor;
            if (boundary) next = { __cursor: boundary };
        }
        if (reverse ? probe : anchor) {
            const boundary = rows.length ? cursor(rows[reverse ? last : 0]) : anchor;
            if (boundary) previous = { __cursor: boundary, __reverse: true };
        }
    }
    return { next, previous };
}
