import type { ReadonlyJSONValue } from '@rocicorp/zero';
import type { Column, ModelContract, ContractMap, QueryArgs, ClientContext, Operation } from './contract';

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

export function keys(value: Record<string, unknown>, allowed: readonly string[], path: string): void {
    for (const key of Object.keys(value))
        if (!allowed.includes(key)) fail(`${path}.${key}`, 'Unknown or protected field.');
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

export function contextValue(ctx: ClientContext, name: string): string | number | boolean {
    const value = ctx[name];
    if (value === null || value === undefined || (typeof value === 'number' && !Number.isFinite(value)))
        fail('context', `Missing context ${name}.`);
    return value;
}

export function validateQueryArgs(
    defs: ContractMap,
    model: ModelContract,
    operation: 'all' | 'one',
    value: unknown,
): QueryArgs {
    const args = object(value, 'args');
    keys(args, operation === 'one' ? ['id', 'include'] : ['filters', 'include', 'order', 'limit'], 'args');
    if (operation === 'one') columnValue(args.id, model.columns[model.key]!, 'id');
    validateRefinement(defs, model, args, 0);
    return args;
}

function validateRefinement(
    defs: ContractMap,
    model: ModelContract,
    args: Record<string, unknown>,
    depth: number,
): void {
    if (depth > 8) fail('include', 'Includes exceed the maximum depth.');
    if (
        args.limit !== undefined &&
        (!Number.isInteger(args.limit) || (args.limit as number) < 1 || (args.limit as number) > model.maxLimit)
    )
        fail('limit', `Limit must be between 1 and ${model.maxLimit}.`);
    if (args.order !== undefined) {
        if (!Array.isArray(args.order)) fail('order', 'Invalid sort order.');
        const used = new Set<string>();
        for (const pair of args.order) {
            if (
                !Array.isArray(pair) ||
                pair.length !== 2 ||
                !model.sorts.includes(pair[0]) ||
                !['asc', 'desc'].includes(pair[1]) ||
                used.has(pair[0])
            )
                fail('order', 'Invalid or disallowed sort.');
            used.add(pair[0]);
        }
    }
    const filters = object(args.filters ?? {}, 'filters');
    keys(filters, Object.keys(model.filters), 'filters');
    for (const [name, value] of Object.entries(filters)) {
        const filter = model.filters[name]!;
        validateFilter(defs, model, filter, value, name);
    }
    const includes = object(args.include ?? {}, 'include');
    keys(includes, model.includes, 'include');
    for (const [name, spec] of Object.entries(includes)) {
        const relation = model.relations[name]!;
        const refinement = object(spec, `include.${name}`);
        keys(refinement, ['include', 'order', 'limit'], `include.${name}`);
        if (refinement.limit !== undefined && (relation.cardinality === 'one' || relation.path.length > 1))
            fail(name, 'Limits require a direct to-many relation.');
        if (refinement.order !== undefined && relation.path.length > 1) fail(name, 'Junction ordering is unsupported.');
        validateRefinement(defs, defs[relation.model]!, refinement, depth + 1);
    }
}

export function freeze<T>(value: T): T {
    if (value !== null && typeof value === 'object' && !Object.isFrozen(value)) {
        Object.values(value).forEach(freeze);
        Object.freeze(value);
    }
    return value;
}

export function validateRules(model: ModelContract, operation: Operation, data: Record<string, unknown>): void {
    validateFormRules(model.validation[operation] ?? {}, model.columns, data);
}

export function validateFormRules(
    rulesByField: Readonly<Record<string, readonly string[]>>,
    columns: Readonly<Record<string, Column>>,
    data: Record<string, unknown>,
): void {
    for (const [field, rules] of Object.entries(rulesByField)) {
        const value = data[field];
        if (value === undefined && rules.includes('sometimes')) continue;
        const empty =
            value === undefined ||
            value === null ||
            (typeof value === 'string' && value.trim() === '') ||
            (Array.isArray(value) && value.length === 0);
        if (rules.includes('required') && empty) fail(field, 'This field is required.');
        if (value === undefined || (value === null && rules.includes('nullable')) || value === '') continue;
        for (const rule of rules) {
            const [kind, ...parameters] = rule.split(':');
            const parameter = parameters.join(':');
            const numeric = columns[field]?.type === 'number' || rules.includes('numeric') || rules.includes('integer');
            const size = numeric
                ? Number(value)
                : typeof value === 'string'
                  ? Array.from(value).length
                  : Array.isArray(value)
                    ? value.length
                    : value !== null && typeof value === 'object'
                      ? Object.keys(value).length
                      : NaN;
            const valid =
                kind === 'min'
                    ? size >= Number(parameter)
                    : kind === 'max'
                      ? size <= Number(parameter)
                      : kind === 'in'
                        ? (typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean') &&
                          parameter.split(',').includes(String(value))
                        : kind === 'string'
                          ? typeof value === 'string'
                          : kind === 'boolean'
                            ? typeof value === 'boolean'
                            : kind === 'integer'
                              ? typeof value === 'number' && Number.isInteger(value)
                              : kind === 'numeric'
                                ? (typeof value === 'number' || typeof value === 'string') &&
                                  value !== '' &&
                                  Number.isFinite(Number(value))
                                : kind === 'uuid'
                                  ? typeof value === 'string' &&
                                    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(value)
                                  : kind === 'ulid'
                                    ? typeof value === 'string' && /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i.test(value)
                                    : true;
            if (!valid) fail(field, `Failed ${rule} validation.`);
        }
    }
}

export function filterComparison(
    model: ModelContract,
    filter: ModelContract['filters'][string],
    input: unknown,
    name: string,
): readonly [string, ReadonlyJSONValue] {
    let value = input;
    let operator: string = filter.operator ?? '=';
    if (operator === 'dynamic') {
        const expression = object(input, name);
        keys(expression, ['operator', 'value'], name);
        if (!['=', '!=', '<', '<=', '>', '>='].includes(expression.operator as string) || !('value' in expression))
            fail(name, 'Expected an allowed operator and value.');
        operator = expression.operator as string;
        value = expression.value;
    }
    const wire = columnValue(value, model.columns[filter.field]!, name);
    if (['partial', 'beginsWith', 'endsWith', 'pattern'].includes(filter.kind)) {
        if (typeof wire !== 'string') fail(name, 'Expected a string.');
        const escaped = wire.replace(/[\\%_]/g, '\\$&');
        return [
            'ILIKE',
            `${filter.kind === 'pattern' ? (filter.prefix ?? '') : filter.kind === 'beginsWith' ? '' : '%'}${escaped}${filter.kind === 'pattern' ? (filter.suffix ?? '') : filter.kind === 'endsWith' ? '' : '%'}`,
        ];
    }
    if (wire === null) {
        if (operator !== '=' && operator !== '!=') fail(name, 'Null only supports equality operators.');
        return [operator === '!=' ? 'IS NOT' : 'IS', null];
    }
    return [operator, wire];
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

export function validateFilter(
    defs: ContractMap,
    model: ModelContract,
    filter: ModelContract['filters'][string],
    value: unknown,
    name: string,
): void {
    if (filter.kind === 'in') {
        if (!Array.isArray(value) || value.length > filter.maxItems!) fail(name, 'Expected a bounded scalar list.');
        for (const item of value) columnValue(item, model.columns[filter.field]!, name);
    } else if (filter.kind === 'conditions') {
        const input = object(value, name);
        keys(input, ['match', 'rules'], name);
        if (
            !['all', 'any'].includes(input.match as string) ||
            !Array.isArray(input.rules) ||
            input.rules.length < 1 ||
            input.rules.length > filter.maxItems!
        )
            fail(name, 'Expected a bounded all/any condition group.');
        for (const item of input.rules as unknown[]) {
            const rule = object(item, name);
            keys(rule, ['field', 'value'], name);
            if (
                typeof rule.field !== 'string' ||
                !Object.hasOwn(filter.conditions!, rule.field) ||
                !Object.hasOwn(rule, 'value')
            )
                fail(name, 'Unknown condition filter.');
            validateFilter(defs, model, filter.conditions![rule.field]!, rule.value, name);
        }
    } else if (filter.kind === 'related' || filter.kind === 'belongsTo') {
        const target = defs[model.relations[filter.field]!.model]!;
        columnValue(value, target.columns[filter.kind === 'related' ? filter.operator! : target.key]!, name);
    } else if (filter.kind === 'trashed') {
        if (!['without', 'with', 'only'].includes(value as string)) fail(name, 'Invalid trashed filter.');
    } else if (filter.kind === 'group') {
        for (const member of filter.members ?? []) validateFilter(defs, model, member, value, name);
    } else if (filter.kind === 'callback' && filter.field === '') {
        if (typeof value !== 'boolean') fail(name, 'Expected a boolean.');
    } else filterComparison(model, filter, value, name);
}
