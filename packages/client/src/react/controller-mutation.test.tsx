// @vitest-environment jsdom
import { act, cleanup, renderHook } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { schema, definitions } from '../../../../tests/interop/generated';
import { createControllerMutationBindings } from '../controller-mutation.fixture';
import {
    store as create,
    type StoreInput,
    type StoreResult,
} from '../../../../tests/interop/controller-api/ZeroForLaravel/Tests/Fixtures/ControllerMutationController';
import { ControllerMutations, useControllerMutation } from './controller-mutation';
import type { ReactNode } from 'react';

function deferred<T>() {
    let resolve!: (value: T) => void;
    const promise = new Promise<T>((done) => {
        resolve = done;
    });
    return { promise, resolve };
}

const native = vi.hoisted(() => ({ mutate: vi.fn() }));
vi.mock('@rocicorp/zero/react', async () => ({ ZeroContext: (await import('react')).createContext(native) }));
afterEach(() => {
    cleanup();
    native.mutate.mockReset();
});
const binding = createControllerMutationBindings(schema, definitions, {
    'ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@projected': async () => ({ pending: true }),
}).actions['ZeroForLaravel\\Tests\\Fixtures\\ControllerMutationController@store'];

it('submits a named generated action with provider defaults and per-hook prepared-field overrides', async () => {
    const id = '784949de-4dd0-4fd1-9297-b9e43e7a9fb7';
    const secondId = 'f98c89a8-9d9c-4ecf-8454-5d150d260bd2';
    const uuid = vi.fn().mockReturnValueOnce(id).mockReturnValueOnce(secondId);
    const date = new Date('2026-10-31T12:34:56.789Z');
    const wrapper = ({ children }: { children: ReactNode }) => (
        <ControllerMutations.Provider
            value={{ actions: { [create.action]: binding }, preparation: { now: () => date } }}
        >
            {children}
        </ControllerMutations.Provider>
    );
    native.mutate.mockImplementation((request) => {
        expect(request.args.input).toEqual({ title: 'Named action' });
        expect(request.args.prepared).toEqual({ ids: { s0: id, s1: secondId }, timestamp: date.getTime() });
        return {
            client: Promise.resolve({ type: 'success' }),
            server: Promise.resolve({ type: 'success', data: { id } }),
        };
    });
    const { result } = renderHook(() => useControllerMutation(create, {}, { uuid }), { wrapper });
    const input: StoreInput = { title: 'Named action' };
    await act(async () => {
        const saved: StoreResult = await result.current(input);
        expect(saved).toEqual({ id });
    });
    expect(result.current.state.status).toBe('success');
});

it('distinguishes local application from server acceptance without resubmitting on rerender', async () => {
    const client = deferred<unknown>();
    const server = deferred<unknown>();
    native.mutate.mockReturnValue({ client: client.promise, server: server.promise });
    const { result, rerender } = renderHook(() => useControllerMutation(binding));
    let submission!: Promise<{ readonly id: string }>;
    act(() => {
        submission = result.current({ title: 'New task' });
    });
    await act(async () => {
        client.resolve({ type: 'success', data: { id: 'predicted' } });
    });
    expect(result.current.state).toMatchObject({ status: 'pending', locallyApplied: true });
    rerender();
    await act(async () => {
        server.resolve({ type: 'success', data: { id: 'authoritative' } });
        expect(await submission).toEqual({ id: 'authoritative' });
    });
    expect(result.current.state.status).toBe('success');
    expect(native.mutate).toHaveBeenCalledOnce();
});

it('keeps validation and committed result errors distinct and ignores late local completion', async () => {
    native.mutate.mockReturnValue({
        client: Promise.resolve({ type: 'success' }),
        server: Promise.resolve({
            type: 'error',
            error: { type: 'app', message: 'Invalid input', details: { fields: { title: ['Too long'] } } },
        }),
    });
    const { result } = renderHook(() => useControllerMutation(binding));
    await act(async () => {
        await expect(result.current({ title: 'Bad' })).rejects.toThrow('Invalid input');
    });
    expect(result.current.state).toMatchObject({ status: 'failure', fields: { title: ['Too long'] } });
    const client = deferred<unknown>();
    native.mutate.mockReturnValue({ client: client.promise, server: Promise.resolve({ type: 'success', data: null }) });
    await act(async () => {
        await expect(result.current({ title: 'Saved' })).rejects.toThrow();
    });
    expect(result.current.state.status).toBe('result-error');
    await act(async () => {
        client.resolve({ type: 'success' });
    });
    expect(result.current.state.status).toBe('result-error');
});
