// @vitest-environment jsdom
import { renderHook } from '@testing-library/react';
import { expect, it, vi } from 'vite-plus/test';
import tasks from '../../../../tests/interop/table.generated';
import { definitions } from '../../../../tests/interop/generated';
import { createTable, type TableOptionInput } from '../index';
import { useTableFilterOptions } from './filter-options';

const catalog = vi.hoisted(() => ({
    records: [
        { id: 'a', name: 'Advocate' },
        { id: 'b', name: 'Board' },
    ],
    status: 'success',
}));
vi.mock('@nickwelsh/zero-for-laravel/react', () => ({
    useData: (selection: { request(): TableOptionInput }, options: { enabled: boolean }) => {
        const input = selection.request();
        const rows = catalog.records.filter((row) =>
            input.operation === 'list' ? row.name.includes(input.search) : input.ids.includes(row.id),
        );
        return {
            status: options.enabled ? catalog.status : 'idle',
            data: rows,
            hasMore: false,
            error: catalog.status === 'stale' ? new Error('Unavailable') : undefined,
        };
    },
}));

it('keeps selected IDs independent of search, hides stale labels and distinguishes unavailable from pending', () => {
    const descriptor = createTable(
        {
            ...tasks.capabilities,
            version: 3,
            relations: {
                tags: {
                    model: 'Tag',
                    table: 'tags',
                    key: 'id',
                    keyColumn: definitions.Tag.columns.id,
                    label: 'Tags',
                    cardinality: 'many',
                    operators: ['any'],
                    filters: {},
                    relations: {},
                    choices: { value: 'id', label: 'name' },
                    lookup: null,
                },
            },
        } as const,
        () => tasks.rows({ filter: null, path: [], groupBy: [], sort: [] }),
        (_path, input) => ({ key: JSON.stringify(input), one: false, request: () => input, decode: () => [] }),
    );
    const { result, rerender } = renderHook(
        ({ enabled }) =>
            useTableFilterOptions({ descriptor }, ['tags'], {
                operator: 'any',
                selected: ['b', 'c'],
                search: 'Adv',
                enabled,
            }),
        { initialProps: { enabled: true } },
    );
    expect(result.current.items).toEqual([{ value: 'a', label: 'Advocate' }]);
    expect(result.current.selectedItems).toEqual([{ value: 'b', label: 'Board' }]);
    expect(result.current.unavailable).toEqual(['c']);
    rerender({ enabled: false });
    expect(result.current.status).toBe('idle');
    expect(result.current.items).toEqual([]);
    expect(result.current.selectedItems).toHaveLength(1);
    catalog.status = 'stale';
    rerender({ enabled: true });
    expect(result.current.selectionStatus).toBe('error');
    expect(result.current.selectedItems).toEqual([]);
    expect(result.current.unavailable).toEqual([]);
});
