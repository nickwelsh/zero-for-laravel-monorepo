// @vitest-environment jsdom
import { defineForm } from '@formbraid/core';
import { defineConfig, useFieldContext, type FieldProps } from '@formbraid/react';
import { act, cleanup, fireEvent, render, renderHook, screen, waitFor } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { Schema } from 'effect';
import { schema, definitions } from '../../../tests/interop/generated';
import { store } from '../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';
import { createControllerMutationBindings } from './controller-mutation.fixture';
import { MutationResultError } from './operation';
import { createControllerForm } from '@nickwelsh/zero-for-laravel/formbraid';

const native = { mutate: vi.fn() };
afterEach(() => {
    cleanup();
    native.mutate.mockReset();
});
const actions = createControllerMutationBindings(schema, definitions, {
    'ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected': async () => ({ pending: true }),
}).actions;
const runtime = () => ({ client: native, actions, preparation: {} });
const definition = defineForm<{ title: string }>()({
    id: 'CreateTask',
    defaultValues: { title: '' },
    fields: {
        title: {
            name: 'title',
            type: 'string',
            component: 'TextField',
            label: 'Title',
            props: {},
            constraints: { required: true },
            nullable: false,
            serverRules: [],
        },
    },
    groups: {},
});
function TextField({ label }: FieldProps<string>) {
    const field = useFieldContext<string>();
    return (
        <label>
            {label}
            <input value={field.value} onChange={(event) => field.setValue(event.target.value)} />
            <span role="alert">{field.errors.join(' ')}</span>
        </label>
    );
}
const { Form, useForm } = defineConfig({ components: { TextField } });
const descriptor = createControllerForm(store, definition, runtime);
function TaskForm() {
    return (
        <Form form={descriptor}>
            {({ fields }) => (
                <>
                    <fields.title />
                    <button type="submit">Save</button>
                </>
            )}
        </Form>
    );
}
function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

it('renders a real Formbraid draft, validates locally, and maps authoritative errors back to the field', async () => {
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({
            type: 'error',
            error: { type: 'app', message: 'Invalid', details: { fields: { title: ['Already taken'] } } },
        }),
    });
    render(<TaskForm />);
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('required'));
    expect(native.mutate).not.toHaveBeenCalled();
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'New task' } });
    fireEvent.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(screen.getByRole('alert').textContent).toBe('Already taken'));
    expect(screen.getByRole('textbox')).toHaveProperty('value', 'New task');
    expect(native.mutate).toHaveBeenCalledOnce();
    expect(native.mutate.mock.calls[0]![0].args.input).toEqual({ title: 'New task' });
});

it('waits past optimistic success, retains typed server data, route bindings, and stable preparation', async () => {
    const server = deferred<unknown>();
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success', data: { id: 'predicted' } }),
        server: server.promise,
    });
    const id = '784949de-4dd0-4fd1-9297-b9e43e7a9fb7';
    const uuid = vi.fn(() => id);
    // This action prepares two distinct IDs; supply a second identity for its related create.
    uuid.mockReturnValueOnce('f98c89a8-9d9c-4ecf-8454-5d150d260bd2');
    const { result, rerender } = renderHook(() => {
        const bound = createControllerForm(store, definition, () => ({ ...runtime(), preparation: { uuid } }), {
            task: 'bound-task',
        });
        return useForm(bound, { defaultValues: { title: 'Submitted' } });
    });
    let pending!: ReturnType<typeof result.current.form.handleSubmit>;
    act(() => {
        pending = result.current.form.handleSubmit();
    });
    await waitFor(() => expect(native.mutate).toHaveBeenCalledOnce());
    expect(result.current.form.getState().isSubmitting).toBe(true);
    rerender();
    act(() => result.current.form.setFieldValue('title', 'Edited while pending'));
    await act(async () => {
        server.resolve({ type: 'success', data: { id: 'authoritative' } });
        const outcome = await pending;
        if (outcome.status !== 'success') throw new Error('Expected success');
        const saved: string = outcome.value.id;
        expect(saved).toBe('authoritative');
    });
    expect(result.current.form.getState().isDirty).toBe(true);
    expect(result.current.form.getState().values.title).toBe('Edited while pending');
    expect(native.mutate).toHaveBeenCalledOnce();
    expect(uuid).toHaveBeenCalledTimes(2);
    expect(native.mutate.mock.calls[0]![0].args.route).toEqual({ task: 'bound-task' });
});

it('uses the controller codec for local field errors and keeps committed decode failures non-retryable', async () => {
    const result = { current: descriptor };
    const context = { signal: new AbortController().signal, onProgress: () => {} };
    await act(async () => {
        const invalid = await result.current.submission.submit({ title: 'x'.repeat(101) }, context);
        expect(invalid).toMatchObject({ status: 'invalid', errors: { title: expect.any(Array) } });
    });
    expect(native.mutate).not.toHaveBeenCalled();
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({ type: 'success', data: null }),
    });
    await act(async () => {
        const outcome = await result.current.submission.submit({ title: 'Saved' }, context);
        expect(outcome).toMatchObject({ status: 'failure', error: expect.any(MutationResultError) });
        if (outcome.status !== 'failure') throw new Error('Expected failure');
        expect(outcome.error).toHaveProperty('committed', true);
    });
    expect(native.mutate).toHaveBeenCalledOnce();
});

it('cancels observation, suppresses late rejection, and never enqueues an already-aborted submission', async () => {
    const server = deferred<unknown>();
    native.mutate.mockReturnValue({ client: Promise.resolve({ type: 'success' }), server: server.promise });
    const onResult = vi.fn();
    const { result } = renderHook(() =>
        useForm(descriptor, {
            defaultValues: { title: 'Durable write' },
            onResult,
        }),
    );
    let pending!: ReturnType<typeof result.current.form.handleSubmit>;
    act(() => {
        pending = result.current.form.handleSubmit();
    });
    await waitFor(() => expect(native.mutate).toHaveBeenCalledOnce());
    await act(async () => {
        result.current.cancel();
        expect(await pending).toEqual({ status: 'cancelled' });
    });
    await act(async () => {
        server.resolve({
            type: 'error',
            error: { type: 'app', message: 'Late', details: { fields: { title: ['Late error'] } } },
        });
    });
    expect(onResult).toHaveBeenCalledOnce();
    expect(result.current.form.getState().errors).toEqual({});
    await act(async () => {
        expect(
            await descriptor.submission.submit(
                { title: 'Do not enqueue' },
                {
                    signal: AbortSignal.abort(),
                    onProgress: () => {},
                },
            ),
        ).toEqual({ status: 'cancelled' });
    });
    expect(native.mutate).toHaveBeenCalledOnce();
});

it('returns cancellation before native completion and preserves non-validation failures', async () => {
    const server = deferred<unknown>();
    native.mutate.mockReturnValue({ client: Promise.resolve({ type: 'success' }), server: server.promise });
    const result = { current: descriptor };
    const abort = new AbortController();
    await act(async () => {
        const pending = result.current.submission.submit(
            { title: 'Pending' },
            {
                signal: abort.signal,
                onProgress: () => {},
            },
        );
        abort.abort();
        expect(await pending).toEqual({ status: 'cancelled' });
    });
    server.resolve({ type: 'success', data: { id: 'still-committed' } });
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({ type: 'error', error: { type: 'app', message: 'Forbidden' } }),
    });
    await act(async () => {
        expect(
            await result.current.submission.submit(
                { title: 'Forbidden' },
                {
                    signal: new AbortController().signal,
                    onProgress: () => {},
                },
            ),
        ).toMatchObject({ status: 'failure', error: { message: 'Forbidden' } });
    });
    expect(native.mutate).toHaveBeenCalledTimes(2);
});

it('decodes a different draft shape before preparing native mutation arguments', async () => {
    const transformed = defineForm<{ title: string; count: string }, { title: string }>()({
        ...definition,
        defaultValues: { title: '', count: '2' },
        schema: Schema.Struct({ title: Schema.String, count: Schema.NumberFromString }).pipe(
            Schema.decodeTo(Schema.Struct({ title: Schema.String })),
        ),
    });
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({ type: 'success', data: { id: 'decoded' } }),
    });
    const { result } = renderHook(() =>
        useForm(createControllerForm(store, transformed, runtime), {
            defaultValues: { title: 'Decoded input', count: '3' },
        }),
    );
    await act(async () => {
        expect(await result.current.form.handleSubmit()).toEqual({ status: 'success', value: { id: 'decoded' } });
    });
    expect(native.mutate.mock.calls[0]![0].args.input).toEqual({ title: 'Decoded input' });
});
