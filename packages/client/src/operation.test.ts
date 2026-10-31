import { expect, it } from 'vite-plus/test';
import type { MutatorResultDetails } from '@rocicorp/zero';
import { completeMutation } from './index';

it('awaits authoritative native outcomes and preserves undefined, null, scalar, and structured data', async () => {
    for (const data of [undefined, null, false, 0, '0', [], { id: 'saved' }]) {
        expect(
            await completeMutation({
                client: Promise.resolve({ type: 'success', data: 'prediction' }),
                server: Promise.resolve({ type: 'success', data }),
            }),
        ).toEqual(data);
    }
    await expect(
        completeMutation({
            client: Promise.resolve({ type: 'success' }),
            server: Promise.resolve({
                type: 'error',
                error: {
                    type: 'app',
                    message: 'Invalid',
                    details: { fields: { title: ['Required'] } },
                },
            }),
        }),
    ).rejects.toMatchObject({ message: 'Invalid', details: { fields: { title: ['Required'] } } });
});

it('cancels observation without undoing or resubmitting a durable write', async () => {
    let accept!: (value: MutatorResultDetails) => void;
    const server = new Promise<MutatorResultDetails>((resolve) => {
        accept = resolve;
    });
    const call = { client: Promise.resolve({ type: 'success' as const }), server };
    const controller = new AbortController();
    const observing = completeMutation(call, controller.signal);
    controller.abort();
    await expect(observing).rejects.toThrow('abort');
    accept({ type: 'success', data: { id: 'committed' } });
    expect(await completeMutation(call)).toEqual({ id: 'committed' });
});
