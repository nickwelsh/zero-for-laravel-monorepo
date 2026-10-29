import type { ModelContract } from '../contract';
import { parseSearchSyntax, mapSearchSyntax, dateValue, relativeDate, expressionSize } from './syntax';
import type { SearchExpression } from './syntax';
import { completeSearchToken } from './completion';
export * from './syntax';
export * from './completion';
export type SearchMetadata = NonNullable<ModelContract['search']>;
export interface GrammarOptions {
    readonly aliases?: Readonly<Record<string, string>>;
    readonly values?: Readonly<Record<string, { readonly field: string; readonly value: string | number | boolean }>>;
    readonly dates?: Readonly<
        Record<string, { readonly field: string; readonly operator: '<' | '>='; readonly relative?: boolean }>
    >;
}
const cmp = (field: string, value: string | number | boolean, op: '=' | '<' | '>=' = '='): SearchExpression => ({
    kind: 'cmp',
    field,
    value,
    op,
});

export function createSearchGrammar(metadata: SearchMetadata, options: GrammarOptions = {}) {
    const aliases = {
        ...Object.fromEntries(Object.keys(metadata.fields).map((field) => [field, field])),
        ...options.aliases,
    };

    function term(operator: string, value: string, now: Date): SearchExpression {
        const fixed = options.values?.[`${operator}:${value.toLowerCase()}`];
        if (fixed) return cmp(fixed.field, fixed.value);
        const date = options.dates?.[operator];
        if (date) return cmp(date.field, date.relative ? relativeDate(value, now) : dateValue(value), date.operator);
        const field = aliases[operator];
        if (!field || !Object.hasOwn(metadata.fields, field))
            throw new Error(`The ${operator}: search operator is not supported.`);
        const type = metadata.fields[field]!;
        if (type === 'bool') {
            if (!['true', 'false'].includes(value)) throw new Error(`${operator}: expects true or false.`);
            return cmp(field, value === 'true');
        }
        if (type === 'string[]') return cmp(field, value.toLowerCase());
        if (type.startsWith('int') || type === 'float') {
            const number = Number(value);
            if (!Number.isFinite(number)) throw new Error(`${operator}: expects a number.`);
            return cmp(field, number);
        }
        return { kind: 'cmp', field, op: 'ILIKE', value: `%${value.replaceAll(/[%_\\]/g, '\\$&')}%` };
    }
    return {
        parse(
            input: string,
            now = new Date(),
        ): { query: string; filter: string; expression?: SearchExpression; error?: string } {
            if (input.trim() === '') return { query: '*', filter: '' };
            if (input.length > 1024) return { query: '*', filter: '', error: 'Keep searches under 1,024 characters.' };
            try {
                if (!/[:(){}]/.test(input) && !/\b(?:OR|AND)\b/.test(input) && !input.trim().startsWith('-'))
                    return { query: input, filter: '' };
                const expression = mapSearchSyntax(parseSearchSyntax(input), (node) =>
                    node.operator === ''
                        ? {
                              kind: 'or',
                              children: metadata.queryBy
                                  .split(',')
                                  .map((field) => ({ kind: 'cmp', field, op: 'ILIKE', value: `%${node.value}%` })),
                          }
                        : term(node.operator, node.value, now),
                );
                if (expressionSize(expression) > 508) throw new Error('Try fewer search terms or groups.');
                return { query: '*', filter: compileSearchExpression(expression, metadata.fields), expression };
            } catch (error) {
                return {
                    query: '*',
                    filter: '',
                    error: error instanceof Error ? error.message : 'Check this search query.',
                };
            }
        },
        suggestions(input: string, caret: number, values: Readonly<Record<string, readonly string[]>> = {}) {
            return completeSearchToken(input, caret, () => [
                ...Object.keys(aliases).map((name) => [`${name}:`, `Filter by ${name.replaceAll('_', ' ')}`] as const),
                ...Object.keys(options.values ?? {}).map((name) => [name, 'Filter results'] as const),
                ...Object.keys(options.dates ?? {}).map((name) => [`${name}:`, 'Filter by date'] as const),
                ...Object.entries(values).flatMap(([name, entries]) =>
                    entries.map((value) => [`${name}:${JSON.stringify(value)}`, `Filter by ${name}`] as const),
                ),
            ]);
        },
    };
}
export function compileSearchExpression(
    expression: SearchExpression,
    fields: Readonly<Record<string, string>>,
): string {
    let remaining = 512;
    function visit(node: SearchExpression, negative = false, depth = 0): string {
        if (--remaining < 0 || depth > 16) throw new Error('Search is too complex.');
        if (node.kind === 'not') return visit(node.child, !negative, depth + 1);
        if (node.kind === 'and' || node.kind === 'or') {
            if (node.children.length === 0) throw new Error('Empty search group.');
            return `(${node.children.map((child) => visit(child, negative, depth + 1)).join((node.kind === 'and') !== negative ? ' && ' : ' || ')})`;
        }
        if (node.kind !== 'cmp' || !Object.hasOwn(fields, node.field)) throw new Error('Unknown search field.');
        let { op, value } = node;
        const inverse: Record<string, string> = { '=': '!=', '!=': '=', '<': '>=', '>=': '<', ILIKE: 'NOT ILIKE' };
        const operator = negative ? inverse[op] : op;
        if (!operator || value === null) throw new Error('Unsupported search comparison.');
        if (typeof value === 'boolean' || typeof value === 'number')
            return `${node.field}:${operator === '=' ? '=' : operator}${value}`;
        const contains = op === 'ILIKE';
        if (contains) value = value.slice(1, -1).replaceAll(/\\([%_\\])/g, '$1');
        // oxlint-disable-next-line eslint/no-control-regex -- Reject filter delimiters and control characters before producing Typesense syntax.
        if (/[`\\\x00-\x1f]/.test(value))
            throw new Error('Backticks, backslashes and control characters are not supported in field filters.');
        const not = operator === '!=' || operator === 'NOT ILIKE';
        return `${node.field}:${contains ? (not ? '!' : '') : not ? '!=' : '='}\`${value}\``;
    }
    return visit(expression);
}
export function rankSearchRows<T>(
    ids: readonly (string | number)[],
    rows: readonly T[],
    key: (row: T) => string | number,
): readonly T[] {
    const byId = new Map(rows.map((row) => [String(key(row)), row]));
    return ids.flatMap((id) => {
        const row = byId.get(String(id));
        return row === undefined ? [] : [row];
    });
}
