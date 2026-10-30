import { describe, expect, it, vi } from 'vite-plus/test';
import { createFormSession } from '@formbraid/core';
import type { MutatorResultDetails } from '@rocicorp/zero';
import { createDomainMutations } from './domain';
import { MutationResultError } from './operation';
import { Task, schema, mutators } from '../../../tests/interop/generated';

const success = { type: 'success', data: { id: 42 } } as const;
const contracts = {
    save: { url: { type: 'string' } },
    update: { id: { type: 'string' }, tags: { type: 'string', array: true, maxItems: 10 } },
} as const;
function domain() {
    return createDomainMutations(schema, mutators, contracts, {
        formRules: { save: { url: ['required', 'string', 'max:20'] } },
        results: { save: { id: { type: 'number' } } },
    });
}
function client(server: Promise<MutatorResultDetails> = Promise.resolve(success)) {
    return {
        mutate: vi.fn(() => ({ client: Promise.resolve({ type: 'success' as const, data: { id: -1 } }), server })),
    };
}

describe('generated operation execution and forms', () => {
    it('uses the authoritative native result, never the optimistic result or a second HTTP read', async () => {
        let accept!: (value: typeof success) => void;
        const transport = client(
            new Promise((resolve) => {
                accept = resolve;
            }),
        );
        let completed = false;
        const pending = domain()
            .actions.save()
            .execute(transport, { url: 'https://EXAMPLE.test' })
            .then((value) => {
                completed = true;
                return value;
            });
        await Promise.resolve();
        expect(completed).toBe(false);
        accept(success);
        expect(await pending).toEqual({ id: 42 });
        expect(transport.mutate).toHaveBeenCalledTimes(1);
    });
    it('binds hidden input and customizes presentation without losing generated validation', async () => {
        const transport = client();
        const form = domain()
            .actions.update()
            .createForm(transport, {
                bind: { id: 'owned' },
                only: ['tags'],
                defaultValues: { tags: ['reading'] },
                fields: { tags: { component: 'TagField', label: 'Labels' } },
            });
        expect(Object.keys(form.definition.fields)).toEqual(['tags']);
        expect(form.definition.fields.tags).toMatchObject({ component: 'TagField', constraints: { maxItems: 10 } });
        expect(form.definition.defaultValues).toEqual({ tags: ['reading'] });
        const result = await form.submission.submit(
            { id: 'forged', tags: ['reference'] },
            { signal: new AbortController().signal },
        );
        expect(result).toMatchObject({ status: 'success', value: { resolved: true } });
        expect(transport.mutate).toHaveBeenCalledWith(
            expect.objectContaining({ args: { id: 'owned', tags: ['reference'] } }),
        );
    });
    it('validates named forms locally and maps authoritative server field errors', async () => {
        const transport = client();
        const form = domain().actions.save().createForm(transport);
        expect(await form.submission.submit({ url: '' }, { signal: new AbortController().signal })).toMatchObject({
            status: 'invalid',
        });
        expect(transport.mutate).not.toHaveBeenCalled();
        const rejected = {
            mutate: () => ({
                client: Promise.resolve(success),
                server: Promise.resolve({
                    type: 'error' as const,
                    error: { type: 'app' as const, message: 'Invalid', details: { fields: { url: ['Invalid URL'] } } },
                }),
            }),
        };
        expect(
            await domain()
                .actions.save()
                .createForm(rejected)
                .submission.submit({ url: 'invalid' }, { signal: new AbortController().signal }),
        ).toEqual({ status: 'invalid', errors: { url: ['Invalid URL'] } });
    });
    it('reports a committed contract failure without resubmitting the operation', async () => {
        const transport = client(Promise.resolve({ type: 'success', data: { id: 'wrong-type' } }));
        const operation = domain().actions.save();
        const form = operation.createForm(transport);
        expect(
            await form.submission.submit({ url: 'https://example.test' }, { signal: new AbortController().signal }),
        ).toMatchObject({ status: 'success', value: { resolved: false, error: expect.any(MutationResultError) } });
        expect(transport.mutate).toHaveBeenCalledTimes(1);
    });
    it('rejects undeclared result fields instead of exposing unchecked response data', async () => {
        const transport = client(Promise.resolve({ type: 'success', data: { id: 42, private: 'hidden' } }));
        await expect(domain().actions.save().execute(transport, { url: 'https://example.test' })).rejects.toThrow(
            MutationResultError,
        );
    });
    it('cancels observation of durable work without resubmitting or undoing the mutation', async () => {
        let accept!: (value: typeof success) => void;
        const transport = client(
            new Promise((resolve) => {
                accept = resolve;
            }),
        );
        const controller = new AbortController();
        const pending = domain()
            .actions.save()
            .createForm(transport)
            .submission.submit({ url: 'https://example.test' }, { signal: controller.signal });
        controller.abort();
        expect(await pending).toEqual({ status: 'cancelled' });
        accept(success);
        await Promise.resolve();
        expect(transport.mutate).toHaveBeenCalledTimes(1);
    });
    it('uses the same form submission contract for model CRUD, including generated identity and local validation', async () => {
        const transport = client();
        const form = Task.create().createForm(transport, { only: ['title'], defaultValues: { title: 'Draft' } });
        const result = await form.submission.submit({ title: 'Saved' }, { signal: new AbortController().signal });
        expect(result).toMatchObject({
            status: 'success',
            value: { resolved: true, data: { id: expect.any(String) } },
        });
        expect(transport.mutate).toHaveBeenCalledTimes(1);
    });
    it('preserves canonical identity through FormBraid session completion and its result observer', async () => {
        const form = domain()
            .actions.save()
            .createForm(client(), { defaultValues: { url: 'https://example.test' } });
        const onResult = vi.fn();
        const session = createFormSession({ definition: form.definition, submit: form.submission.submit, onResult });
        try {
            await expect(session.submit()).resolves.toEqual({
                status: 'success',
                value: { resolved: true, data: { id: 42 } },
            });
            expect(onResult).toHaveBeenCalledWith({ status: 'success', value: { resolved: true, data: { id: 42 } } });
        } finally {
            session.dispose();
        }
    });
});
