import { withoutPosition, type LivePagination, type PagePosition } from '../pagination';
import { fail, object } from '../validation';

/** Versioned UTF-8 payload; the receiver still validates exact ordering fields and their wire types. */
function cursorToken(row: Readonly<Record<string, unknown>>, reverse: boolean) {
    const bytes = new TextEncoder().encode(JSON.stringify([1, reverse, row]));
    return btoa(Array.from(bytes, (byte) => String.fromCharCode(byte)).join(''))
        .replaceAll('+', '-')
        .replaceAll('/', '_')
        .replaceAll('=', '');
}

function decodeCursor(name: string, value: string): PagePosition {
    try {
        if (!/^[\w-]+$/u.test(value)) fail(name, 'Invalid cursor.');
        const bytes = Uint8Array.from(atob(value.replaceAll('-', '+').replaceAll('_', '/')), (char) =>
            char.charCodeAt(0),
        );
        const tuple: unknown = JSON.parse(new TextDecoder('utf-8', { fatal: true }).decode(bytes));
        if (!Array.isArray(tuple) || tuple.length !== 3 || tuple[0] !== 1 || typeof tuple[1] !== 'boolean')
            fail(name, 'Invalid cursor.');
        return { __cursor: object(tuple[2], name), ...(tuple[1] ? { __reverse: true } : {}) };
    } catch {
        return fail(name, 'Invalid cursor.');
    }
}

export function paginatedArgs(
    args: Record<string, unknown>,
    pagination: LivePagination,
    url: URL,
    scrollPage?: number,
) {
    const { mode, name } = pagination;
    const values = url.searchParams.getAll(name);
    if (values.length > 1 || [...url.searchParams.keys()].some((field) => field.startsWith(`${name}[`)))
        fail(name, 'Expected one pagination parameter.');
    const value = values[0];
    let position: PagePosition;
    if (mode === 'cursor') position = value === undefined ? {} : decodeCursor(name, value);
    else {
        if (value !== undefined && !/^[1-9]\d*$/u.test(value)) fail(name, 'Expected a positive page number.');
        const page = value === undefined ? 1 : Number(value);
        // A warm history entry remembers the start of the expanded scroll range. A cold URL has only the end.
        if (page === (mode === 'scroll' ? (scrollPage ?? pagination.initial.page) : pagination.initial.page))
            return args;
        position = { __page: page };
    }
    return { ...withoutPosition(args), ...pagination.range(position).wire };
}

export function writePagination(search: URLSearchParams, pagination: LivePagination) {
    const { name, initial } = pagination;
    const row = initial.wire['__cursor'];
    if (row) search.set(name, cursorToken(row, initial.wire['__reverse'] === true));
    else if (initial.page > 1) search.set(name, String(initial.page));
    else search.delete(name);
}
