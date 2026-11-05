// @vitest-environment jsdom
import { defineForm } from '@formbraid/core';
import { defineConfig, useFieldContext, useFieldOptions, withOptionSources, type FieldProps } from '@formbraid/react';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { StrictMode, useState, useSyncExternalStore } from 'react';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import { bindings, inputs, optionQueries } from '../../../../tests/interop/options.generated';
import { optionContains } from '../validation';
import { createZeroOptionSource } from './formbraid';

const selected = '8e44e540-61b1-47c7-8316-074f1fe04be8';
const parent = 'faea2c26-5636-4e2a-a1d6-7d2d8e0278df';
const native = vi.hoisted(() => ({
    list: [] as unknown[],
    selected: undefined as unknown,
    details: { type: 'complete' } as { type: string; error?: unknown },
    zero: { context: { user_id: 'alice' } },
    revision: 0,
    listeners: new Set<() => void>(),
    requests: [] as { query: { queryName: string }; args: Record<string, unknown> }[],
}));
vi.mock('@rocicorp/zero/react', () => ({
    useQuery: (request: { query: { queryName: string }; args: Record<string, unknown> }) => {
        native.requests.push(request);
        useSyncExternalStore(
            (listener) => {
                native.listeners.add(listener);
                return () => {
                    native.listeners.delete(listener);
                };
            },
            () => native.revision,
        );
        return [request.query.queryName === optionQueries.resolve.name ? native.selected : native.list, native.details];
    },
    useZero: () => native.zero,
    useConnectionState: () => ({ name: 'connected' }),
}));
afterEach(cleanup);
beforeEach(() => {
    native.list = [];
    native.selected = { id: selected, title: 'Outside the search window' };
    native.details = { type: 'complete' };
    native.requests = [];
});
function publish() {
    act(() => {
        native.revision++;
        native.listeners.forEach((listener) => listener());
    });
}

const adapterConfig = {
    value: 'id',
    label: 'title',
    list: {
        inputs: optionQueries.list.inputs,
        select: (args) => {
            const parsed = inputs[optionQueries.list.name].safeParse(args);
            return parsed.success ? bindings[optionQueries.list.name](parsed.data) : undefined;
        },
    },
    resolve: {
        inputs: optionQueries.resolve.inputs,
        select: (args) => {
            const parsed = inputs[optionQueries.resolve.name].safeParse(args);
            return parsed.success ? bindings[optionQueries.resolve.name](parsed.data) : undefined;
        },
    },
} satisfies Parameters<typeof createZeroOptionSource>[0];
const Adapter = createZeroOptionSource(adapterConfig);
const fieldDefinition = {
    name: 'reference',
    type: 'string',
    component: 'Picker',
    label: 'Party',
    props: {},
    constraints: {},
    nullable: true,
    serverRules: [],
} as const;
const source = 'OptionRequest:reference';
const definition = defineForm<{ done: boolean; reference: string | null }>()({
    id: 'Options',
    defaultValues: { done: false, reference: selected },
    groups: {},
    fields: {
        reference: {
            ...fieldDefinition,
            options: {
                kind: 'source',
                id: source,
                inputs: Object.values(optionQueries.list.inputs),
                resolveInputs: Object.values(optionQueries.resolve.inputs),
            },
        },
        done: { ...fieldDefinition, name: 'done', type: 'boolean', component: 'Dependency', nullable: false },
    },
});
function Picker(_props: FieldProps<string | null>) {
    const field = useFieldContext<string | null>();
    const [value, setValue] = useState('');
    const [open, setOpen] = useState(false);
    const { listing, selection } = useFieldOptions({ value, enabled: open });
    return (
        <>
            <input aria-label="Filter" value={value} onChange={(event) => setValue(event.target.value)} />
            <button type="button" onClick={() => setOpen(!open)}>
                Toggle
            </button>
            <output aria-label="Listing">{listing.status}</output>
            <output aria-label="Selection">
                {selection.status === 'complete' ? selection.item.label : selection.status}
            </output>
            {listing.items.map((item) => (
                <button type="button" key={item.value} onClick={() => field.setValue(String(item.value))}>
                    {item.label}
                </button>
            ))}
        </>
    );
}
function Dependency(_props: FieldProps<boolean>) {
    const field = useFieldContext<boolean>();
    return (
        <input
            type="checkbox"
            aria-label="Done"
            checked={field.value}
            onChange={(event) => field.setValue(event.target.checked)}
        />
    );
}
const { Form, useForm } = defineConfig({ components: { Picker, Dependency } });
const submit = vi.fn(async (_values: { done: boolean; reference: string | null }) => ({
    status: 'success' as const,
    value: null,
}));
const descriptor = withOptionSources(
    { version: 1 as const, definition, submission: { submit } },
    { [source]: Adapter },
    { task: parent },
);
function Example({ form = descriptor }: { form?: typeof descriptor }) {
    const instance = useForm(form);
    return (
        <Form form={instance}>
            {({ fields }) => (
                <>
                    <fields.reference />
                    <fields.done />
                    <button type="submit">Save</button>
                </>
            )}
        </Form>
    );
}

it('resolves a prefilled ID while closed, searches independently, and submits only the selected ID', async () => {
    submit.mockClear();
    native.list = [{ id: parent, title: 'Matching party' }];
    render(
        <StrictMode>
            <Example />
        </StrictMode>,
    );
    await waitFor(() => expect(screen.getByLabelText('Selection').textContent).toBe('Outside the search window'));
    expect(screen.getByLabelText('Listing').textContent).toBe('idle');
    expect(native.requests.every((request) => request.query.queryName === optionQueries.resolve.name)).toBe(true);
    fireEvent.change(screen.getByLabelText('Filter'), { target: { value: 'Matching' } });
    fireEvent.click(screen.getByText('Toggle'));
    await waitFor(() => expect(screen.getByRole('button', { name: 'Matching party' })).toBeTruthy());
    expect(
        native.requests.filter((request) => request.query.queryName === optionQueries.list.name).at(-1)?.args,
    ).toEqual({
        __window: 21,
        i0: parent,
        i1: false,
        i2: 'Matching',
    });
    fireEvent.click(screen.getByLabelText('Done'));
    await waitFor(() =>
        expect(
            native.requests.filter((request) => request.query.queryName === optionQueries.list.name).at(-1)?.args.i1,
        ).toBe(true),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Matching party' }));
    fireEvent.click(screen.getByText('Save'));
    await waitFor(() => expect(submit).toHaveBeenCalled());
    expect(submit.mock.calls[0]?.[0]).toEqual({ done: true, reference: parent });
});

it('waits for missing context or invalid input, without treating an empty value as missing', async () => {
    const missing = withOptionSources(
        { version: 1 as const, definition, submission: { submit } },
        { [source]: Adapter },
    );
    const view = render(<Example form={missing} />);
    fireEvent.click(screen.getByText('Toggle'));
    expect(screen.getByLabelText('Listing').textContent).toBe('idle');
    expect(screen.getByLabelText('Selection').textContent).toBe('idle');
    expect(native.requests).toHaveLength(0);
    view.rerender(<Example />);
    await waitFor(() => expect(screen.getByLabelText('Listing').textContent).toBe('complete'));
    fireEvent.change(screen.getByLabelText('Filter'), { target: { value: 'x'.repeat(257) } });
    await waitFor(() => expect(screen.getByLabelText('Listing').textContent).toBe('idle'));
    expect(screen.getByLabelText('Selection').textContent).toBe('Outside the search window');
    fireEvent.change(screen.getByLabelText('Filter'), { target: { value: '' } });
    await waitFor(() => expect(screen.getByLabelText('Listing').textContent).toBe('complete'));
    expect(
        native.requests.filter((request) => request.query.queryName === optionQueries.list.name).at(-1)?.args.i2,
    ).toBe('');
});

it('does not publish partial or failed data, and distinguishes empty search from unavailable selection', async () => {
    native.list = [{ id: parent, title: 'Cached option' }];
    native.details = { type: 'unknown' };
    render(<Example />);
    fireEvent.click(screen.getByText('Toggle'));
    await waitFor(() => expect(screen.getByLabelText('Listing').textContent).toBe('pending'));
    expect(screen.queryByRole('button', { name: 'Cached option' })).toBeNull();
    native.details = { type: 'error', error: { message: 'Denied', details: { status: 403 } } };
    publish();
    await waitFor(() => expect(screen.getByLabelText('Selection').textContent).toBe('error'));
    expect(screen.queryByRole('button', { name: 'Cached option' })).toBeNull();
    native.list = [];
    native.selected = undefined;
    native.details = { type: 'complete' };
    publish();
    await waitFor(() => expect(screen.getByLabelText('Listing').textContent).toBe('complete'));
    await waitFor(() => expect(screen.getByLabelText('Selection').textContent).toBe('unavailable'));
});

it.each([
    ['', '%%'],
    ['50%_\\', '%50\\%\\_\\\\%'],
    ['café', '%café%'],
])('escapes literal containment text %j', (value, expected) => {
    expect(optionContains(value)).toBe(expected);
});

it('offers growing live prefixes through the same Formbraid source without losing selected-ID resolution', async () => {
    native.list = Array.from({ length: 21 }, (_, index) => ({ id: `${index}`, title: `Choice ${index}` }));
    render(
        <Adapter
            operation="list"
            enabled
            inputs={[
                { reference: { kind: 'context', key: 'task' }, present: true, value: parent },
                { reference: { kind: 'field', path: 'done' }, present: true, value: false },
                { reference: { kind: 'value' }, present: true, value: '' },
            ]}
        >
            {(state) => (
                <>
                    <output>{state.items.length} choices</output>
                    {state.hasMore && (
                        <button type="button" onClick={() => state.loadMore?.()}>
                            More choices
                        </button>
                    )}
                </>
            )}
        </Adapter>,
    );
    await waitFor(() => expect(screen.getByText('20 choices')).toBeDefined());
    expect(screen.queryByText('21 choices')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'More choices' }));
    await waitFor(() => expect(screen.getByText('21 choices')).toBeDefined());
    expect(screen.queryByRole('button', { name: 'More choices' })).toBeNull();
});
