/* oxlint-disable eslint/no-underscore-dangle -- Algolia exposes the originating SearchParameters as SearchResults._state. */
import { useMemo, type ReactNode } from 'react';
import { Configure, InstantSearch, useInfiniteHits, useInstantSearch } from 'react-instantsearch';
import TypesenseInstantSearchAdapter from 'typesense-instantsearch-adapter';
import type { SearchMetadata } from '../search';
import { useSearchRefresh } from './use-search-refresh';
export interface ScoutSearchProviderProps {
    readonly metadata: SearchMetadata;
    readonly endpoint: string;
    readonly query: string;
    readonly filters?: string;
    readonly sort?: string;
    readonly revisionEndpoint?: string | undefined;
    readonly children: ReactNode;
}
/** InstantSearch owns requests, refinements, caches, pagination and cancellation of obsolete searches. */
export function ScoutSearchProvider({
    metadata,
    endpoint,
    query,
    filters = '',
    sort,
    revisionEndpoint,
    children,
}: ScoutSearchProviderProps) {
    const client = useMemo(() => {
        const url = new URL(endpoint, typeof location === 'undefined' ? 'http://localhost' : location.origin);
        return new TypesenseInstantSearchAdapter({
            server: {
                apiKey: 'session',
                nodes: [
                    {
                        host: url.hostname,
                        port: Number(url.port || (url.protocol === 'https:' ? 443 : 80)),
                        protocol: url.protocol === 'https:' ? 'https' : 'http',
                        path: url.pathname.replace(/\/multi_search$/, ''),
                    },
                ],
                additionalHeaders: {
                    'X-CSRF-TOKEN':
                        typeof document === 'undefined'
                            ? ''
                            : (document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? ''),
                    Accept: 'application/json',
                    'content-type': 'application/json',
                },
                cacheSearchResultsForSeconds: 0,
                numRetries: 0,
            },
            additionalSearchParameters: {
                query_by: metadata.queryBy,
                include_fields: [...new Set(['id', metadata.key])].join(','),
                highlight_fields: 'none',
            },
        }).searchClient;
    }, [endpoint, metadata.queryBy, metadata.key]);
    const index = sort ? `${metadata.index}/sort/${sort}` : metadata.index;
    return (
        <InstantSearch searchClient={client} indexName={index} future={{ preserveSharedStateOnUnmount: true }}>
            <Configure query={query} filters={filters} hitsPerPage={25} />
            <SearchRefresh endpoint={revisionEndpoint} index={metadata.index} />
            {children}
        </InstantSearch>
    );
}
/** Hits are identifiers only. Canonical records must come from an authorized Zero selection. */
export function useScoutHits(key = 'id') {
    const { items, results, isLastPage, showMore } = useInfiniteHits();
    const { status, error, refresh } = useInstantSearch({ catchError: true });
    const ids = items
        .map((item) => item[key] ?? (key === 'id' ? item.objectID : undefined))
        .filter((id): id is string | number => typeof id === 'string' || typeof id === 'number')
        .slice(0, 1000);
    return {
        ids,
        found: results?.nbHits ?? 0,
        loading: status === 'loading' || status === 'stalled',
        error,
        hasMore: !isLastPage && ids.length < 1000,
        showMore,
        refresh,
        query: results?.query,
        filters: results?._state.filters ?? '',
        index: results?._state.index,
    };
}

function SearchRefresh({ endpoint, index }: { endpoint: string | undefined; index: string }) {
    const { refresh } = useInstantSearch();
    useSearchRefresh(endpoint, index, refresh);
    return null;
}
