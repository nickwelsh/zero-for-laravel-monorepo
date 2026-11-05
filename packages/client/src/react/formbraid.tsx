import type { Option, OptionInputReference } from '@formbraid/core';
import type { OptionListState, OptionSourceProps, ResolvedOptionInput } from '@formbraid/react';
import { createElement, type ComponentType, type ReactNode } from 'react';
import { useData, type ReadSelection } from './data';

/** Generated argument names bind to portable references; query validation decides readiness. */
export interface ZeroOptionOperation {
    readonly inputs: Readonly<Record<string, OptionInputReference>>;
    readonly select: (args: Readonly<Record<string, unknown>>) => ReadSelection | undefined;
}
/** Optional infinite-prefix extension; remains compatible with Formbraid 0.3 sources. */
export type ZeroOptionListState = OptionListState & {
    readonly hasMore?: boolean | undefined;
    readonly loadMore?: () => void;
};
export type ZeroOptionSourceProps =
    | Exclude<OptionSourceProps, { operation: 'list' }>
    | (Omit<Extract<OptionSourceProps, { operation: 'list' }>, 'children'> & {
          readonly children: (state: ZeroOptionListState) => ReactNode;
      });
export type ZeroOptionSourceAdapter = ComponentType<ZeroOptionSourceProps>;

export function createZeroOptionSource(config: {
    readonly list: ZeroOptionOperation;
    readonly resolve?: ZeroOptionOperation;
    readonly value: string;
    readonly label: string;
}): ZeroOptionSourceAdapter {
    function Feed({ source, selection }: { source: ZeroOptionSourceProps; selection: ReadSelection }) {
        const data = useData(selection);
        if (data.status === 'failure' || data.status === 'stale') {
            return source.operation === 'list'
                ? source.children({ status: 'error', items: [], error: data.error })
                : source.children({ status: 'error', error: data.error });
        }
        // Do not publish persisted, partial, or obsolete rows as selectable results.
        if (data.status !== 'success') {
            return source.operation === 'list'
                ? source.children({ status: 'pending', items: [] })
                : source.children({ status: 'pending' });
        }
        try {
            if (source.operation === 'list') {
                if (!Array.isArray(data.data)) throw new Error('Option listing must return an array.');
                return source.children({
                    status: 'complete',
                    items: data.data.map(option),
                    hasMore: data.hasMore,
                    loadMore: () => data.loadMore(),
                });
            }
            const item = data.data === undefined ? undefined : option(data.data);
            return source.children(
                item && item.value === source.selectedValue ? { status: 'complete', item } : { status: 'unavailable' },
            );
        } catch (error) {
            return source.operation === 'list'
                ? source.children({ status: 'error', items: [], error })
                : source.children({ status: 'error', error });
        }
    }

    function option(row: unknown): Option {
        if (row === null || typeof row !== 'object') throw new Error('Invalid option row.');
        const record = row as Record<string, unknown>;
        const value = record[config.value];
        const label = record[config.label];
        if ((typeof value !== 'string' && typeof value !== 'number') || typeof label !== 'string')
            throw new Error('Invalid option value or label.');
        return { value, label };
    }

    return function ZeroOptionSource(source) {
        const operation = source.operation === 'list' ? config.list : config.resolve;
        const idle = () =>
            source.operation === 'list'
                ? source.children({ status: 'idle', items: [] })
                : source.children({ status: 'idle' });
        if (!source.enabled) return idle();
        if (!operation) return source.operation === 'resolve' ? source.children({ status: 'unavailable' }) : idle();
        const args: Record<string, unknown> = {};
        for (const [name, reference] of Object.entries(operation.inputs)) {
            const input = source.inputs.find((candidate) => sameReference(candidate, reference));
            if (!input?.present) return idle();
            args[name] = input.value;
        }
        const selection = operation.select(args);
        return selection ? createElement(Feed, { key: selection.key, source, selection }) : idle();
    };
}

function sameReference(input: ResolvedOptionInput, reference: OptionInputReference): boolean {
    if (input.reference.kind !== reference.kind) return false;
    if (reference.kind === 'field') return input.reference.kind === 'field' && input.reference.path === reference.path;
    if (reference.kind === 'context')
        return input.reference.kind === 'context' && input.reference.key === reference.key;
    return true;
}
