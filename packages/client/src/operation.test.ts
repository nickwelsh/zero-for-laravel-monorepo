import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { createFormSession } from '@formbraid/core';
import { createDomainMutations } from './domain';
import { MutationResultError } from './operation';
import { Task, schema, mutators } from '../../../tests/interop/generated';

const success = { type: 'success' } as const;
const contracts = {
    save: { url: { type: 'string' } },
    update: { id: { type: 'string' }, tags: { type: 'string', array: true, maxItems: 10 } },
} as const;
function domain() {
    return createDomainMutations(schema, mutators, contracts, {
        formRules: { save: { url: ['required', 'string', 'max:20'] } },
        results: { save: { id: { type: 'number' } } },
        resultURL: '/zero/results',
    });
}
function client(server = Promise.resolve(success)) {
    return { mutate: vi.fn(() => ({ client: Promise.resolve(success), server })) };
}
afterEach(() => vi.unstubAllGlobals());
function resultTransport(status = 200, body: unknown = { id: 42 }) {
    vi.stubGlobal('location', { href: 'https://example.test/bookmarks', origin: 'https://example.test' });
    vi.stubGlobal('document', { cookie: 'XSRF-TOKEN=csrf%20token' });
    const fetch = vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status }));
    vi.stubGlobal('fetch', fetch);
    return fetch;
}

describe('generated operation execution and forms', () => {
    it('resolves canonical identity only after native server acceptance, with session and CSRF protection', async () => {
        const fetch = resultTransport();
        let accept!: (value: typeof success) => void;
        const transport = client(
            new Promise((resolve) => {
                accept = resolve;
            }),
        );
        const pending = domain().actions.save().execute(transport, { url: 'https://EXAMPLE.test' });
        await Promise.resolve();
        expect(fetch).not.toHaveBeenCalled();
        accept(success);
        expect(await pending).toEqual({ id: 42 });
        expect(fetch).toHaveBeenCalledWith(
            new URL('https://example.test/zero/results/save'),
            expect.objectContaining({
                method: 'POST',
                credentials: 'same-origin',
                headers: expect.objectContaining({ 'X-XSRF-TOKEN': 'csrf token' }),
                body: JSON.stringify({ url: 'https://EXAMPLE.test' }),
            }),
        );
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
    it('returns a committed warning when result resolution fails and permits retrying only the read', async () => {
        const fetch = resultTransport(503);
        const transport = client();
        const operation = domain().actions.save();
        const form = operation.createForm(transport);
        expect(
            await form.submission.submit({ url: 'https://example.test' }, { signal: new AbortController().signal }),
        ).toMatchObject({ status: 'success', value: { resolved: false, error: expect.any(MutationResultError) } });
        fetch.mockResolvedValue(new Response(JSON.stringify({ id: 42 })));
        expect(await operation.resolveResult({ url: 'https://example.test' })).toEqual({ id: 42 });
        expect(transport.mutate).toHaveBeenCalledTimes(1);
    });
    it('rejects undeclared result fields instead of exposing unchecked response data', async () => {
        resultTransport(200, { id: 42, private: 'hidden' });
        await expect(domain().actions.save().execute(client(), { url: 'https://example.test' })).rejects.toThrow(
            MutationResultError,
        );
    });
    it('cancels observation of durable work without running result reads or success effects', async () => {
        const fetch = resultTransport();
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
        expect(fetch).not.toHaveBeenCalled();
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
        resultTransport();
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
