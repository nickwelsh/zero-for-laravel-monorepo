import { usePage } from '@inertiajs/react';
import { inertiaURLAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import { useMemo } from 'react';
import { useDataTable as useReactDataTable, type TableOptions } from './react';
import type { Capabilities, TableDescriptor } from './index';
export * from './react';

/** useDataTable with URL state integrated into Inertia's client-side page/history lifecycle. */
export function useDataTable<Row, C extends Capabilities>(
    descriptor: TableDescriptor<Row, C>,
    options: TableOptions<NoInfer<C>, NoInfer<Row>> = {},
) {
    const { url } = usePage();
    const adapter = useMemo(
        () => (typeof window === 'undefined' ? { ...inertiaURLAdapter, getURL: () => url } : inertiaURLAdapter),
        [url],
    );
    return useReactDataTable(
        descriptor,
        options.urlState
            ? {
                  ...options,
                  urlState: {
                      adapter,
                      ...(typeof options.urlState === 'object' ? options.urlState : {}),
                  },
              }
            : options,
    );
}
