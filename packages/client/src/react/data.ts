import { useQuery, useZero, useConnectionState, type UseQueryOptions } from '@rocicorp/zero/react';
import type { Schema, QueryResultDetails, ConnectionState, QueryRequest } from '@rocicorp/zero';
import { useMemo, useState } from 'react';
import type { ClientContext, ModelContract, QueryArgs } from '../contract';
import { selectionKey, type SelectionData } from '../query';

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
    loadMore(): void;
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
    retry(): void;
    render<R>(handlers: RenderHandlers<T, R>): R;
}

export interface ReadSelection {
    readonly key: string;
    readonly contract: ModelContract;
    readonly args: Readonly<QueryArgs>;
    readonly pageSize?: number | undefined;
    readonly one: boolean;
    readonly schema?: unknown;
    readonly definitions?: unknown;
    readonly queries?: unknown;
    readonly mutators?: unknown;
    readonly defaults?: unknown;
    request(): unknown;
    decode(raw: unknown): unknown;
    limit?(limit: number): ReadSelection;
}

// Stable semantics keep decoding local to this hook and its authorization identity.
function useSelection<Q extends ReadSelection>(selection: Q, identity: unknown, context: string): Q {
    // Selection identity is defined by its immutable contract and semantic key.
    return useMemo(
        () => selection,
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
        [
            selection.key,
            selection.contract,
            selection.schema,
            selection.definitions,
            selection.queries,
            selection.mutators,
            selection.defaults,
            identity,
            context,
        ],
    );
}

type NativeRequest = QueryRequest<
    string,
    QueryArgs,
    QueryArgs,
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
    const max = selection.contract.maxLimit;
    const initial = Math.min(selection.args.limit ?? selection.contract.defaultLimit, max);
    // Only root ordering may change within a retained scope. Includes and their
    // ordering, filters, limits, contracts, and authorization remain part of it.
    const { order: _order, ...scopeArgs } = selection.args;
    const scopeKey = selectionKey([selection.contract.name, selection.one, scopeArgs, selection.pageSize]);
    const window = useMemo(
        () => ({
            scopeKey,
            zero,
            context,
            contract: selection.contract,
            schema: selection.schema,
            definitions: selection.definitions,
            queries: selection.queries,
            mutators: selection.mutators,
            defaults: selection.defaults,
        }),
        [
            scopeKey,
            zero,
            context,
            selection.contract,
            selection.schema,
            selection.definitions,
            selection.queries,
            selection.mutators,
            selection.defaults,
        ],
    );
    const [page, setPage] = useState({ window, limit: initial });
    if (page.window !== window) setPage({ window, limit: initial });
    const limit = Math.min(page.window === window ? page.limit : initial, max);
    const scope = useMemo(() => ({ window, limit }), [window, limit]);
    const requested = selection.pageSize === undefined ? selection.args.limit : Math.min(limit + 1, max);
    const request = useMemo(
        () =>
            (selection.pageSize === undefined || selection.one
                ? selection.request()
                : selection.limit!(requested!).request()) as NativeRequest,
        // The selection object may be recreated by a consumer or compiler while
        // its query remains identical. Native resource identity follows semantics.
        // oxlint-disable-next-line react-hooks-plugin/exhaustive-deps
        [selection.key, window, requested],
    );
    const [raw, details] = useQuery(request, options);
    const enabled = options.enabled ?? true;
    const [committed, setCommitted] = useState({ scope, selection, request, complete: false });
    const retain =
        enabled &&
        committed.complete &&
        committed.scope === scope &&
        committed.request !== request &&
        details.type !== 'complete';
    // A second native subscription keeps the committed ordering live. Zero owns
    // materialization, deduplication, TTL, retries, and subscription cleanup.
    const [previousRaw] = useQuery(retain ? committed.request : undefined, options);
    if (
        committed.scope !== scope ||
        (enabled && details.type === 'complete' && (committed.request !== request || !committed.complete))
    ) {
        setCommitted({ scope, selection, request, complete: enabled && details.type === 'complete' });
    }
    const visibleSelection = retain ? committed.selection : selection;
    const visibleRaw = retain ? previousRaw : raw;
    const decodedResult = useMemo(() => {
        try {
            return { value: visibleSelection.decode(visibleRaw), error: undefined };
        } catch (error) {
            return {
                value: visibleSelection.one ? undefined : [],
                error: error instanceof Error ? error : new Error(String(error)),
            };
        }
    }, [visibleRaw, visibleSelection]);
    const decoded = decodedResult.value;
    const data = useMemo(
        () =>
            (selection.pageSize !== undefined && Array.isArray(decoded) ? decoded.slice(0, limit) : decoded) as Result,
        [decoded, selection.pageSize, limit],
    );
    const complete = details.type === 'complete';
    const hasData =
        !decodedResult.error &&
        (retain ||
            (committed.scope === scope && committed.request === request && committed.complete) ||
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
                          error ||
                          !complete ||
                          !Array.isArray(decoded) ||
                          decoded.length <= limit ||
                          capped
                      )
                          return;
                      setPage((current) => ({
                          window,
                          limit: Math.min(
                              (current.window === window ? current.limit : initial) + selection.pageSize!,
                              max,
                          ),
                      }));
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
