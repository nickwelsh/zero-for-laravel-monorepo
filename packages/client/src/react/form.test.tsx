// @vitest-environment jsdom
import { formDataToObject } from '@inertiajs/core';
import { act, cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { createRef, StrictMode } from 'react';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import type { NativeFormAction } from './form-action';
import { Form, type FormRef } from './index';

function descriptor(key = 'store') {
    return {
        action: {
            key,
            submit: vi.fn<NativeFormAction['submit']>().mockResolvedValue({ saved: true }),
            normalize: vi.fn((data: Record<string, unknown>) => data),
            takeRecovery: vi.fn<NativeFormAction['takeRecovery']>().mockReturnValue(undefined),
        },
    };
}
afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it('keeps the Wayfinder create JSX, renders field errors, and normalizes only successful controls', async () => {
    const store = descriptor();
    const TodoController = { store: { form: () => store } };
    store.action.normalize.mockImplementation((data) => ({
        ...data,
        ...('open_after_saving' in data ? { open_after_saving: data.open_after_saving === '1' } : {}),
    }));
    store.action.submit.mockRejectedValueOnce({ details: { fields: { title: ['Required', 'Too short'] } } });
    render(
        <Form {...TodoController.store.form()}>
            {({ errors, processing }) => (
                <>
                    <label>
                        Title <input name="title" />
                    </label>
                    {errors.title && <p role="alert">{errors.title}</p>}
                    <label>
                        <input type="checkbox" name="open_after_saving" value="1" /> Open after saving
                    </label>
                    <button disabled={processing}>Create todo</button>
                </>
            )}
        </Form>,
    );
    const button = screen.getByRole('button', { name: 'Create todo' });
    fireEvent.click(button);
    expect((await screen.findByRole('alert')).textContent).toBe('Required');
    expect(store.action.normalize).toHaveBeenCalledWith({ title: '' });
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Normalized' } });
    fireEvent.click(screen.getByLabelText('Open after saving'));
    fireEvent.click(button);
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(store.action.submit).toHaveBeenLastCalledWith(
        { title: 'Normalized', open_after_saving: true },
        { draft: expect.any(FormData) },
    );
});

it('packs named controls like Inertia, includes only the clicked submitter, then transforms and normalizes', async () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    const transform = vi.fn((data: Record<string, unknown>) => ({ ...data, quantity: '007' }));
    action.normalize.mockImplementation((data) => ({ ...data, quantity: Number(data.quantity) }));
    const view = render(
        <>
            <Form action={action} ref={ref} transform={transform} id="edit">
                <input name="profile.name" defaultValue="Ada" />
                <input name="profile[street.address]" defaultValue="Main" />
                <input name={'config\\.key'} defaultValue="literal dot" />
                <input name="items[0][name]" defaultValue="First" />
                <input name="items.1.name" defaultValue="Second" />
                <input name="sparse[2]" defaultValue="sparse" />
                <input name="tags[]" defaultValue="a" />
                <input name="tags[]" defaultValue="b" />
                <input name="later[4]" defaultValue="indexed" />
                <input name="later[]" defaultValue="append" />
                <input name="color" defaultValue="red" />
                <input name="color" defaultValue="blue" />
                <select multiple name="choices[]" defaultValue={['x', 'z']}>
                    <option>x</option>
                    <option>y</option>
                    <option>z</option>
                </select>
                <input type="file" name="unselected" />
                <input name="disabled" disabled />
                <input defaultValue="no name" />
                <button name="intent" value="unused">
                    Unused
                </button>
                <button name="intent" value="save">
                    Save
                </button>
            </Form>
            <input form="edit" name="external" defaultValue="associated" />
        </>,
    );
    const save = screen.getByRole('button', { name: 'Save' });
    const form = view.container.querySelector('form')!;
    const expected = formDataToObject(new FormData(form, save));
    expect(ref.current!.getData()).toEqual(formDataToObject(new FormData(form)));
    fireEvent.click(save);
    expect(transform).toHaveBeenCalledWith(expected);
    expect(action.normalize).toHaveBeenCalledWith({ ...expected, quantity: '007' });
    expect(action.submit.mock.calls[0]?.[0]).toEqual({ ...expected, quantity: 7 });
    expect(Array.from(action.submit.mock.calls[0]![1]!.draft.keys())).not.toContain('intent');
    await waitFor(() => expect(ref.current!.wasSuccessful).toBe(true));
});

it('exposes defaults, partial resets and error methods through refs, and honors native reset buttons', () => {
    const ref = createRef<FormRef>();
    render(
        <Form {...descriptor()} ref={ref}>
            <input aria-label="name" name="user[name]" defaultValue="original" />
            <input aria-label="visible" type="checkbox" name="visible" defaultChecked />
            <button type="reset">Restore</button>
        </Form>,
    );
    const name = screen.getByLabelText('name');
    fireEvent.change(name, { target: { value: 'new default' } });
    expect(ref.current!.isDirty).toBe(true);
    act(() => ref.current!.defaults());
    expect(ref.current!.isDirty).toBe(false);
    fireEvent.change(name, { target: { value: 'changed again' } });
    fireEvent.click(screen.getByLabelText('visible'));
    act(() => {
        ref.current!.setError('user.name', 'Invalid');
        ref.current!.setError({ visible: 'Missing', other: 'Other' });
    });
    act(() => ref.current!.resetAndClearErrors('user.name'));
    expect(ref.current!.getData()).toEqual({ user: { name: 'new default' } });
    expect(ref.current!.errors).toEqual({ visible: 'Missing', other: 'Other' });
    expect(ref.current!.isDirty).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Restore' }));
    expect(ref.current!.getFormData().get('visible')).toBe('on');
    expect(ref.current!.isDirty).toBe(false);
    act(() => ref.current!.clearErrors());
    expect(ref.current!.hasErrors).toBe(false);
});

it('can veto before enqueue, locks against duplicate submits, and awaits the success callback before finish', async () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    const events: string[] = [];
    const before = vi
        .fn(() => {
            events.push('before');
            return true;
        })
        .mockReturnValueOnce(false);
    let finish!: () => void;
    const pause = new Promise<void>((resolve) => {
        finish = resolve;
    });
    const page = render(
        <Form
            action={action}
            ref={ref}
            disableWhileProcessing
            resetOnSuccess={['title']}
            setDefaultsOnSuccess
            onBefore={before}
            onStart={() => {
                events.push('start');
            }}
            onSuccess={async (result) => {
                expect(result).toEqual({ saved: true });
                events.push('success');
                await pause;
            }}
            onSubmitComplete={() => {
                events.push('complete');
            }}
            onFinish={() => {
                events.push('finish');
            }}
        >
            <input name="title" defaultValue="initial" aria-label="title" />
            <input name="memo" aria-label="memo" />
        </Form>,
    );
    fireEvent.change(screen.getByLabelText('title'), { target: { value: 'edited' } });
    fireEvent.change(screen.getByLabelText('memo'), { target: { value: 'keep' } });
    await act(() => ref.current!.submit());
    expect(action.submit).not.toHaveBeenCalled();
    let submitted!: Promise<void>;
    await act(async () => {
        submitted = ref.current!.submit();
        await ref.current!.submit();
    });
    expect(ref.current!.processing).toBe(true);
    expect(page.container.querySelector('form')!.hasAttribute('inert')).toBe(true);
    expect(events).toEqual(['before', 'start', 'success']);
    await act(async () => {
        finish();
        await submitted;
    });
    expect(events).toEqual(['before', 'start', 'success', 'complete', 'finish']);
    expect(action.submit).toHaveBeenCalledOnce();
    expect(ref.current).toMatchObject({
        processing: false,
        wasSuccessful: true,
        recentlySuccessful: true,
        isDirty: false,
    });
    expect(ref.current!.getData()).toEqual({ title: 'initial', memo: 'keep' });
    fireEvent.change(screen.getByLabelText('memo'), { target: { value: 'different' } });
    act(() => ref.current!.reset());
    expect(ref.current!.getData()).toEqual({ title: 'initial', memo: 'keep' });
});

it('maps normalization failures and form-level submission errors without erasing unrelated controls', async () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    const onError = vi.fn();
    action.normalize.mockImplementationOnce(() => {
        throw { issues: [{ path: ['items', 0, 'title'], message: 'Too short' }] };
    });
    render(
        <Form action={action} ref={ref} onError={onError} resetOnError={['note']}>
            <input name="items[0][title]" defaultValue="x" />
            <input aria-label="note" name="note" defaultValue="default" />
        </Form>,
    );
    fireEvent.change(screen.getByLabelText('note'), { target: { value: 'edited' } });
    await act(() => ref.current!.submit());
    expect(onError).toHaveBeenCalledWith({ 'items.0.title': 'Too short' });
    expect(ref.current!.getData()).toEqual({ items: [{ title: 'x' }], note: 'default' });
    expect(action.submit).not.toHaveBeenCalled();
    action.submit.mockRejectedValueOnce(new Error('Permission denied'));
    await act(() => ref.current!.submit());
    expect(ref.current!.errors).toEqual({ _form: 'Permission denied' });
});

it('restores a failed draft on re-entry without replacing the defaults, even in Strict Mode', () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    const draft = new FormData();
    draft.set('title', 'draft');
    draft.set('open', '1');
    action.takeRecovery.mockReturnValueOnce({ draft, error: { details: { fields: { title: ['Taken'] } } } });
    render(
        <StrictMode>
            <Form action={action} ref={ref}>
                <input name="title" defaultValue="server" />
                <input name="open" type="checkbox" value="1" />
            </Form>
        </StrictMode>,
    );
    expect(ref.current).toMatchObject({ isDirty: true, errors: { title: 'Taken' }, processing: false });
    expect(ref.current!.getData()).toEqual({ title: 'draft', open: '1' });
    act(() => ref.current!.resetAndClearErrors());
    expect(ref.current!.getData()).toEqual({ title: 'server' });
    expect(ref.current!.isDirty).toBe(false);
    expect(action.submit).not.toHaveBeenCalled();
});

it('preserves a mounted edit across fresh descriptors but never delivers old completions to another route', async () => {
    const { action } = descriptor('update/1');
    const ref = createRef<FormRef>();
    let reject!: (error: Error) => void;
    action.submit.mockImplementation(
        () =>
            new Promise((_resolve, fail) => {
                reject = fail;
            }),
    );
    const onError = vi.fn();
    const onFinish = vi.fn();
    const edit = (current: NativeFormAction, title: string) => (
        <Form action={current} ref={ref} onError={onError} onFinish={onFinish}>
            <input aria-label="Title" name="title" defaultValue={title} />
        </Form>
    );
    const page = render(edit(action, 'Original'));
    fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Draft' } });
    page.rerender(edit({ ...action }, 'Original'));
    expect(ref.current!.getData()).toEqual({ title: 'Draft' });
    let pending!: Promise<void>;
    act(() => {
        pending = ref.current!.submit();
    });
    page.rerender(edit(descriptor('update/2').action, 'Another'));
    await act(async () => {
        reject(new Error('Late'));
        await pending;
    });
    expect(onError).not.toHaveBeenCalled();
    expect(onFinish).not.toHaveBeenCalled();
    expect(ref.current).toMatchObject({ processing: false, hasErrors: false, isDirty: false });
    expect(ref.current!.getData()).toEqual({ title: 'Another' });
});

it('rejects DOM file entries even if a transform would drop them, and rejects files added by transforms', async () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    const file = new File(['body'], 'upload.txt');
    // jsdom cannot construct a browser FileList for a file input. Supply the same successful FormData entry.
    vi.stubGlobal(
        'FormData',
        class extends FormData {
            constructor(form?: HTMLFormElement) {
                super(form);
                if (form) this.set('attachment', file);
            }
        },
    );
    const page = render(
        <Form action={action} ref={ref} transform={() => ({})}>
            <input type="file" name="attachment" />
        </Form>,
    );
    await act(() => ref.current!.submit());
    expect(ref.current!.errors).toEqual({ _form: expect.stringMatching(/cannot submit files/) });
    vi.unstubAllGlobals();
    page.rerender(
        <Form action={action} ref={ref} transform={() => ({ nested: [file] })}>
            <input name="title" />
        </Form>,
    );
    await act(() => ref.current!.submit());
    expect(ref.current!.errors).toEqual({ _form: expect.stringMatching(/cannot submit files/) });
    expect(action.normalize).not.toHaveBeenCalled();
    expect(action.submit).not.toHaveBeenCalled();
});

it.each(['options', 'method', 'headers', 'onProgress', 'onCancelToken', 'optimistic', 'validationTimeout'])(
    'rejects unsupported %s instead of forwarding it to the DOM',
    (key) => {
        const props = { ...descriptor(), [key]: true };
        expect(() => render(<Form {...props}>No visit</Form>)).toThrow(`'${key}'`);
    },
);

it('never falls back to an HTTP action or submitter override', async () => {
    const { action } = descriptor();
    const ref = createRef<FormRef>();
    expect(() => render(<Form action={'/todos' as unknown as NativeFormAction}>Invalid</Form>)).toThrow(
        /not an HTTP URL/,
    );
    render(
        <Form action={action} ref={ref}>
            <button formAction="/todos">Override</button>
        </Form>,
    );
    fireEvent.click(screen.getByRole('button'));
    await waitFor(() => expect(ref.current!.errors).toEqual({ _form: expect.stringMatching(/formaction/) }));
    expect(action.submit).not.toHaveBeenCalled();
});
