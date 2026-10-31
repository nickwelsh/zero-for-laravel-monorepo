import type { ReadonlyJSONValue } from '@rocicorp/zero';
import type { Column } from './contract';

export class InputError extends Error {
    constructor(public readonly fields: Readonly<Record<string, string>>) {
        super(
            Object.entries(fields)
                .map(([field, message]) => `${field}: ${message}`)
                .join('; '),
        );
        this.name = 'InputError';
    }
}

export function fail(field: string, message: string): never {
    throw new InputError({ [field]: message });
}

export function object(value: unknown, path: string): Record<string, unknown> {
    if (
        value === null ||
        typeof value !== 'object' ||
        Array.isArray(value) ||
        (Object.getPrototypeOf(value) !== Object.prototype && Object.getPrototypeOf(value) !== null)
    )
        fail(path, 'Expected an object.');
    return value as Record<string, unknown>;
}

export function columnValue(value: unknown, column: Column, path: string, publicDate = false): ReadonlyJSONValue {
    if (value === null && column.nullable) return null;
    if (column.date && publicDate) {
        if (!(value instanceof Date) || !Number.isFinite(value.getTime())) fail(path, 'Expected a valid Date.');
        return encodeDate(value, column.dateFormat);
    }
    if (publicDate && column.cast) {
        const expected =
            column.cast === 'boolean'
                ? 'boolean'
                : column.cast === 'integer' || column.cast === 'float'
                  ? 'number'
                  : column.cast === 'string'
                    ? 'string'
                    : 'json';
        if (expected === 'json') {
            if (!isJson(value) || typeof value !== 'object' || value === null)
                fail(path, 'Expected JSON array or object.');
            if (column.type === 'string') value = JSON.stringify(value);
        } else {
            if (
                typeof value !== expected ||
                (typeof value === 'number' &&
                    (!Number.isFinite(value) || (column.cast === 'integer' && !Number.isInteger(value))))
            )
                fail(path, 'Invalid cast value.');
            if (column.type === 'string') value = column.cast === 'boolean' ? (value ? '1' : '0') : String(value);
            else if (column.type === 'number') value = Number(value);
            else if (column.type === 'boolean') value = value !== false && value !== 0 && value !== '' && value !== '0';
        }
    }
    const valid =
        column.type === 'json'
            ? isJson(value)
            : typeof value === column.type && (column.type !== 'number' || Number.isFinite(value));
    if (!valid || value === null || (column.enum && !column.enum.includes(value as string | number)))
        fail(path, 'Invalid field value.');
    if (column.date && column.type === 'number' && Math.abs(value as number) > 8640000000000000)
        fail(path, 'Expected epoch milliseconds.');
    return value as ReadonlyJSONValue;
}

export function encodeDate(value: Date, format?: Column['dateFormat']): string | number {
    if (!Number.isFinite(value.getTime())) fail('date', 'Expected a valid Date.');
    if (format === undefined) return value.getTime();
    const iso = value.toISOString();
    if (format === 'Y-m-d') return iso.slice(0, 10);
    const seconds = iso.slice(0, 19).replace('T', ' ');
    return format === 'Y-m-d H:i:s.u' ? `${seconds}.${iso.slice(20, 23)}000` : seconds;
}

function isJson(value: unknown, ancestors = new Set<unknown>()): value is ReadonlyJSONValue {
    if (value === null || typeof value === 'string' || typeof value === 'boolean') return true;
    if (typeof value === 'number') return Number.isFinite(value);
    if (typeof value !== 'object' || ancestors.has(value)) return false;
    if (
        !Array.isArray(value) &&
        Object.getPrototypeOf(value) !== Object.prototype &&
        Object.getPrototypeOf(value) !== null
    )
        return false;
    ancestors.add(value);
    const valid = Object.values(value).every((v) => isJson(v, ancestors));
    ancestors.delete(value);
    return valid;
}

export function freeze<T>(value: T): T {
    if (value !== null && typeof value === 'object' && !Object.isFrozen(value)) {
        Object.values(value).forEach(freeze);
        Object.freeze(value);
    }
    return value;
}

/** Canonicalize object keys; ordered arrays (including sort precedence) stay ordered. */
export function selectionKey(value: unknown): string {
    return JSON.stringify(value, (_key, child: unknown) =>
        child && typeof child === 'object' && !Array.isArray(child)
            ? // oxlint-disable-next-line unicorn/no-array-sort -- Entries is a freshly owned array.
              Object.fromEntries(Object.entries(child).sort(([a], [b]) => a.localeCompare(b)))
            : child,
    );
}

export function decodeColumn(value: unknown, column: Column, field: string): unknown {
    if (value === null || value === undefined) return value;
    if (column.enum && !column.enum.includes(value as string | number)) fail(field, 'Invalid stored enum value.');
    if (column.date && (typeof value === 'number' || typeof value === 'string')) {
        const date = new Date(
            typeof value === 'string' && column.dateFormat
                ? `${value.replace(' ', 'T')}${value.length > 10 ? 'Z' : ''}`
                : value,
        );
        if (!Number.isFinite(date.getTime())) fail(field, 'Invalid stored date.');
        return date;
    }
    if (column.cast === 'boolean') return value !== false && value !== 0 && value !== '' && value !== '0';
    if (column.cast === 'integer' || column.cast === 'float') {
        const number = Number(value);
        if (!Number.isFinite(number)) fail(field, 'Invalid stored numeric cast.');
        return column.cast === 'integer' ? Math.trunc(number) : number;
    }
    if (column.cast === 'string') {
        if (typeof value !== 'string' && typeof value !== 'number' && typeof value !== 'boolean')
            fail(field, 'String casts require a scalar stored value.');
        return typeof value === 'boolean' ? (value ? '1' : '') : String(value);
    }
    if ((column.cast === 'array' || column.cast === 'object') && typeof value === 'string') {
        try {
            return JSON.parse(value);
        } catch {
            fail(field, 'Invalid stored JSON.');
        }
    }
    return structuredClone(value);
}
