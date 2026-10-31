import type { AnyCustomQuery, QueryRowType, QueryResultType } from '@rocicorp/zero';
import type { ContractMap, FieldValue } from './contract';
import type { ReadSelection } from './react/data';
import { selectionKey } from './query';
import { decodeColumn, fail, freeze, object } from './validation';

type NativeQuery = Pick<AnyCustomQuery, 'queryName' | '~'> & ((...args: never[]) => unknown);

export type InferQueryInput<Q extends (...args: never[]) => unknown> = Parameters<Q>[0];
/** Native Zero rows, including wire-format dates (epoch milliseconds). */
export type InferQueryResult<Q extends (...args: never[]) => ReturnType<AnyCustomQuery>> = QueryResultType<
    ReturnType<Q>
>;
/** Decoded rows for the optional live-data adapter, not native Zero results. */
export type InferLiveQueryResult<Q extends { live: (...args: never[]) => { decode: (...args: never[]) => unknown } }> =
    ReturnType<ReturnType<Q['live']>['decode']>;

export type InferLiveQueryInputs<Q extends { live: (...args: never[]) => { input: { values: object } } }> = ReturnType<
    Q['live']
>['input']['values'];

export type InferLiveQueryOptions<Q extends { live: (...args: never[]) => { input: { options: object } } }> =
    ReturnType<Q['live']>['input']['options'];

/** Generated input metadata exposes only the FormRequest, never route or identity arguments. */
export function withQueryInputs<
    S extends ReadSelection,
    A extends object,
    K extends keyof A | null,
    O extends Record<string, readonly (string | number)[]>,
>(selection: S, args: A, field: K, options: O, parse: (value: unknown) => A) {
    type Values = K extends keyof A ? Extract<A[K], object> : Record<string, never>;
    const values = freeze(structuredClone(field === null ? {} : args[field])) as Readonly<Values>;
    return Object.freeze({
        ...selection,
        input: {
            values,
            options: freeze(options),
            apply(value: object): A {
                if (field === null && Object.keys(value).length) fail('inputs', 'This query has no editable inputs.');
                const { __cursor: _cursor, __window: _window, ...base } = object(args, 'args');
                return parse(field === null ? base : { ...base, [field]: value });
            },
        },
    });
}

type DecodedRow<D extends ContractMap, N extends keyof D & string, R> = R extends undefined
    ? undefined
    : {
          readonly [K in keyof R]: K extends keyof D[N]['columns']
              ? FieldValue<D, N, K>
              : K extends keyof D[N]['relations']
                ? R[K] extends readonly (infer Child)[]
                    ? readonly DecodedRow<D, D[N]['relations'][K]['model'], Child>[]
                    : DecodedRow<D, D[N]['relations'][K]['model'], R[K]>
                : never;
      };

export type LiveQueryResult<D extends ContractMap, N extends keyof D & string, Q extends NativeQuery> =
    undefined extends QueryRowType<Q>
        ? DecodedRow<D, N, QueryRowType<Q>>
        : readonly DecodedRow<D, N, QueryRowType<Q>>[];

/** Generated bindings pass validated wire input and retain native query identity. */
export function liveQuery<Q extends NativeQuery, D extends ContractMap, N extends keyof D & string>(
    query: Q,
    args: Parameters<Q>[0],
    definitions: D,
    model: N,
    one: boolean,
    pageSize?: number,
    cursorFields?: readonly string[],
) {
    const input = freeze(structuredClone(args));
    const { __cursor: _cursor, ...pageInput } = object(input, 'args');
    const invoke = query as (args: Parameters<Q>[0]) => ReturnType<Q>;
    const request = invoke(input);
    const cache = new WeakMap<object, unknown>();
    function decode(raw: unknown, name: string): unknown {
        if (raw === undefined) return undefined;
        if (raw === null || typeof raw !== 'object') fail('result', 'Expected a replicated row.');
        if (cache.has(raw)) return cache.get(raw);
        if (Array.isArray(raw)) {
            const rows = Object.freeze(raw.map((row) => decode(row, name)));
            cache.set(raw, rows);
            return rows;
        }
        const source = object(raw, 'result');
        const contract = definitions[name];
        if (!contract) fail('result', 'Unknown result model.');
        const row: Record<string, unknown> = Object.create(null);
        for (const [field, value] of Object.entries(source)) {
            const column = contract.columns[field];
            const relation = contract.relations[field];
            if (column) row[field] = decodeColumn(value, column, field);
            else if (relation) row[field] = decode(value, relation.model);
            else fail('result', `Unexpected replicated field ${field}.`);
        }
        Object.freeze(row);
        cache.set(raw, row);
        return row;
    }
    const selection = {
        key: selectionKey([query.queryName, input, pageSize, cursorFields]),
        one,
        pageSize,
        cursorFields,
        definitions,
        queries: query,
        request: () => request,
        decode: (raw: unknown): LiveQueryResult<D, N, Q> => {
            const value = raw === undefined && !one ? [] : raw;
            if (!one && !Array.isArray(value)) fail('result', 'Expected a replicated collection.');
            if (one && Array.isArray(value)) fail('result', 'Expected a single replicated row.');
            return decode(value, model) as LiveQueryResult<D, N, Q>;
        },
        limit: (window: number): ReadSelection => ({
            ...selection,
            key: selectionKey([query.queryName, input, window]),
            request: () => invoke({ ...object(input, 'args'), __window: window } as Parameters<Q>[0]),
        }),
        page: (cursor: Readonly<Record<string, unknown>> | undefined): ReadSelection => ({
            ...selection,
            key: selectionKey([query.queryName, input, cursor]),
            request: () =>
                invoke({ ...pageInput, ...(cursor === undefined ? {} : { __cursor: cursor }) } as Parameters<Q>[0]),
        }),
        cursor: (raw: unknown): Readonly<Record<string, unknown>> => {
            const row = object(raw, 'cursor');
            return Object.freeze(Object.fromEntries((cursorFields ?? []).map((field) => [field, row[field] ?? null])));
        },
    };
    return Object.freeze(selection);
}
