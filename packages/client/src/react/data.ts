import { useQuery, useZero, useConnectionState, type UseQueryOptions } from '@rocicorp/zero/react';
import type { Schema, QueryResultDetails, ConnectionState, QueryRequest, ReadonlyJSONValue } from '@rocicorp/zero';
import { useMemo, useState } from 'react';
import type { ClientContext } from '../contract';
import { selectionKey } from '../validation';

type SelectionData<Q> = Q extends { decode(raw: unknown): infer Result } ? Result : never;

export type DataStatus = 'idle' | 'loading' | 'refreshing' | 'failure' | 'stale' | 'success';
export type RenderHandlers<T, R> = {
    onIdle: () => R;
    onLoading: () => R;
    onRefreshing: (data: T) => R;
    onFailure: (error: Error) => R;
    onStale: (value: readonly [T, Error]) => R;
    onSuccess: (data: T) => R;
};
export interface InfiniteData {
    readonly hasMore: boolean | undefined;
    readonly limit: number;
    readonly capped: boolean;
    /** True only when synchronization proves there are no further rows. */
    readonly exhausted: boolean;
    readonly stopReason: 'maximum' | 'exhausted' | undefined;
    loadMore(this: void): void;
}
export interface Data<T> {
    readonly data: T;
    readonly status: DataStatus;
    readonly complete: boolean;
    readonly enabled: boolean;
    readonly error: Error | undefined;
    readonly connection: ConnectionState;
    readonly details: QueryResultDetails;
    readonly infinite: InfiniteData | undefined;
    readonly hasMore: boolean | undefined;
    readonly isLoadingMore: boolean;
    readonly capped: boolean;
    readonly exhausted: boolean;
    readonly stopReason: 'maximum' | 'exhausted' | undefined;
    loadMore(this: void): void;
    readonly hasNextPage: boolean | undefined;
    readonly hasPreviousPage: boolean;
    readonly isChangingPage: boolean;
    nextPage(this: void): void;
    previousPage(this: void): void;
    retry(this: void): void;
    render<R>(handlers: RenderHandlers<T, R>): R;
}

export interface ReadSelection {
    readonly key: string;
    readonly pageSize?: number | undefined;
    readonly cursorFields?: readonly string[] | undefined;
    readonly one: boolean;
    readonly definitions?: unknown;
    readonly queries?: unknown;
    readonly input?: {
        readonly values: object;
        readonly options: Readonly<Record<string, readonly (string | number)[]>>;
        apply(values: object): object;
    };
    request(): unknown;
    decode(raw: unknown): unknown;
    limit?(limit: number): ReadSelection;
    page?(cursor: Readonly<Record<string, unknown>> | undefined): ReadSelection;
    cursor?(raw: unknown): Readonly<Record<string, unknown>>;
}

export interface QueryInputs<I extends object, O extends object> {
    readonly values: Readonly<I>;
    readonly options: Readonly<O>;
    set(this: void, patch: Partial<I> | ((current: Readonly<I>) => Partial<I>)): void;
    reset(this: void): void;
}

export type QueryData<
    Q extends { live: (...args: never[]) => ReadSelection & { input: { values: object; options: object } } },
> = Data<SelectionData<ReturnType<Q['live']>>> & {
    readonly inputs: QueryInputs<ReturnType<Q['live']>['input']['values'], ReturnType<Q['live']>['input']['options']>;
};

// Stable semantics keep decoding local to this hook and its authorization identity.
function useSelection<Q extends ReadSelection>(selection: Q, identity: unknown, context: string): Q {
    return useMemo(
        () => selection,
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
        [selection.key, selection.definitions, selection.queries, identity, context],
    );
}

type NativeRequest = QueryRequest<
    string,
    ReadonlyJSONValue,
    ReadonlyJSONValue,
    Schema,
    Record<string, unknown> | undefined,
    ClientContext
>;

export function useData<Q extends ReadSelection>(input: Q, options: UseQueryOptions = {}): Data<SelectionData<Q>> {
    type Result = SelectionData<Q>;
    const zero = useZero<Schema, undefined, ClientContext>();
    const connection = useConnectionState();
    const context = selectionKey(zero.context);
    const selection = useSelection(input, zero, context);
    const max = 2147483647;
    const initial = Math.min(selection.pageSize ?? max, max);
    // All arguments belong to the controller query identity, including sort input.
    const window = useMemo(() => ({ selection, zero, context }), [selection, zero, context]);
    const [page, setPage] = useState({
        window,
        limit: initial,
        cursors: [] as readonly Readonly<Record<string, unknown>>[],
    });
    if (page.window !== window) setPage({ window, limit: initial, cursors: [] });
    const limit = Math.min(page.window === window ? page.limit : initial, max);
    const cursor = page.window === window ? page.cursors.at(-1) : undefined;
    const scope = useMemo(() => ({ window, limit, cursor }), [window, limit, cursor]);
    const requested = selection.pageSize === undefined ? undefined : Math.min(limit + 1, max);
    const request = useMemo(
        () =>
            (selection.cursorFields !== undefined
                ? selection.page!(cursor).request()
                : selection.pageSize === undefined || selection.one
                  ? selection.request()
                  : selection.limit!(requested!).request()) as NativeRequest,
        // The selection object may be recreated by a consumer or compiler while
        // its query remains identical. Native resource identity follows semantics.
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
        [selection.key, window, requested, cursor],
    );
    const [raw, details] = useQuery(request, options);
    const enabled = options.enabled ?? true;
    const [committed, setCommitted] = useState({ scope, complete: false });
    if (committed.scope !== scope || (enabled && details.type === 'complete' && !committed.complete)) {
        setCommitted({ scope, complete: enabled && details.type === 'complete' });
    }
    const decodedResult = useMemo(() => {
        try {
            return { value: selection.decode(raw), error: undefined };
        } catch (error) {
            return {
                value: selection.one ? undefined : [],
                error: error instanceof Error ? error : new Error(String(error)),
            };
        }
    }, [raw, selection]);
    const decoded = decodedResult.value;
    const data = useMemo(
        () =>
            (selection.pageSize !== undefined && Array.isArray(decoded) ? decoded.slice(0, limit) : decoded) as Result,
        [decoded, selection.pageSize, limit],
    );
    const complete = details.type === 'complete';
    const hasData =
        !decodedResult.error &&
        ((committed.scope === scope && committed.complete) ||
            complete ||
            (Array.isArray(data) ? data.length > 0 : data !== undefined));
    const error = decodedResult.error ?? (details.type === 'error' ? new Error(details.error.message) : undefined);
    const status: DataStatus = !enabled
        ? 'idle'
        : error
          ? hasData
              ? 'stale'
              : 'failure'
          : complete
            ? 'success'
            : hasData
              ? 'refreshing'
              : 'loading';
    const exhausted =
        !decodedResult.error &&
        complete &&
        Array.isArray(decoded) &&
        decoded.length <= limit &&
        (requested! > limit || decoded.length < limit);
    const capped = limit >= max;
    const infinite: InfiniteData | undefined =
        selection.pageSize === undefined
            ? undefined
            : {
                  limit,
                  capped,
                  exhausted,
                  stopReason:
                      !enabled || error || !complete
                          ? undefined
                          : exhausted
                            ? 'exhausted'
                            : capped
                              ? 'maximum'
                              : undefined,
                  // At the cap, advancement is impossible even when exhaustion is unknown.
                  hasMore: !enabled
                      ? undefined
                      : capped
                        ? false
                        : error || !complete
                          ? undefined
                          : Array.isArray(decoded) && decoded.length > limit
                            ? true
                            : exhausted
                              ? false
                              : undefined,
                  loadMore: () => {
                      if (
                          !enabled ||
                          selection.cursorFields !== undefined ||
                          error ||
                          !complete ||
                          !Array.isArray(decoded) ||
                          decoded.length <= limit ||
                          capped
                      )
                          return;
                      setPage((current) =>
                          current.window !== window || current.limit !== limit
                              ? current
                              : {
                                    ...current,
                                    limit: Math.min(limit + selection.pageSize!, max),
                                },
                      );
                  },
              };
    return {
        data,
        status,
        complete,
        enabled,
        error,
        connection,
        details,
        infinite,
        hasMore: infinite?.hasMore,
        capped: infinite?.capped ?? false,
        exhausted: infinite?.exhausted ?? false,
        stopReason: infinite?.stopReason,
        isLoadingMore: selection.pageSize !== undefined && limit > initial && !complete,
        loadMore: () => infinite?.loadMore(),
        hasNextPage: selection.cursorFields === undefined ? undefined : infinite?.hasMore,
        hasPreviousPage: selection.cursorFields !== undefined && page.window === window && page.cursors.length > 0,
        isChangingPage: selection.cursorFields !== undefined && !complete,
        nextPage: () => {
            if (selection.cursorFields === undefined || infinite?.hasMore !== true || !Array.isArray(raw)) return;
            const next = selection.cursor!(raw[limit - 1]);
            setPage((current) =>
                current !== page || current.window !== window
                    ? current
                    : { ...current, cursors: [...current.cursors, next] },
            );
        },
        previousPage: () => {
            if (selection.cursorFields === undefined || !enabled || !complete || error) return;
            setPage((current) =>
                current !== page || current.window !== window
                    ? current
                    : { ...current, cursors: current.cursors.slice(0, -1) },
            );
        },
        retry: () => {
            if (details.type === 'error') details.retry();
        },
        render: (handlers) => {
            switch (status) {
                case 'idle':
                    return handlers.onIdle();
                case 'loading':
                    return handlers.onLoading();
                case 'refreshing':
                    return handlers.onRefreshing(data);
                case 'failure':
                    return handlers.onFailure(error!);
                case 'stale':
                    return handlers.onStale([data, error!]);
                default:
                    return handlers.onSuccess(data);
            }
        },
    };
}
