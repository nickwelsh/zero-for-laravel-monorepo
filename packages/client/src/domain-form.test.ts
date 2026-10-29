import { describe, expect, it } from 'vite-plus/test';
import { createDomainForms, completeMutation, nativeMutationForm } from './domain-form';
const success = { type: 'success' } as const;
describe('native named-action forms', () => {
    it('generates portable field metadata from the action contract and request rules', () => {
        const forms = createDomainForms(
            {
                save: { url: { type: 'string' }, tags: { type: 'string', array: true, optional: true, maxItems: 10 } },
            } as const,
            { save: { url: ['required', 'string', 'max:8192'] } },
        );
        expect(forms.save.fields.url.constraints).toMatchObject({ required: true, maxLength: 8192 });
        expect(forms.save.fields.tags.constraints.maxItems).toBe(10);
        expect(forms.save.defaultValues).toEqual({ url: '' });
    });
    it('surfaces client rejection even when a server response never arrives', async () => {
        await expect(
            completeMutation({
                client: Promise.resolve({
                    type: 'error',
                    error: { type: 'app', message: 'Denied', details: undefined },
                }),
                server: new Promise(() => {}),
            }),
        ).rejects.toThrow('Denied');
    });
    it('maps authoritative validation errors and stops observing cancellation without undoing durable work', async () => {
        const definition = { id: 'save', fields: {}, defaultValues: { url: '' }, groups: {} };
        const form = nativeMutationForm(definition, () => ({
            client: Promise.resolve(success),
            server: Promise.resolve({
                type: 'error',
                error: { type: 'app', message: 'Invalid', details: { fields: { url: ['Invalid URL'] } } },
            }),
        }));
        expect(await form.submission.submit({ url: 'invalid' }, { signal: new AbortController().signal })).toEqual({
            status: 'invalid',
            errors: { url: ['Invalid URL'] },
        });
        const controller = new AbortController();
        controller.abort();
        expect(await form.submission.submit({ url: 'unused' }, { signal: controller.signal })).toEqual({
            status: 'cancelled',
        });
    });
});
