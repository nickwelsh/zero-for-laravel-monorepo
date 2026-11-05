import { expect, it } from 'vite-plus/test';
import type { SubmissionResult } from '@formbraid/core';
import { MutationResultError } from '@nickwelsh/zero-for-laravel';
import { formbraidSubmit, editorFromForm } from './formbraid';
import { parseEditorValue, type CellChange } from './index';

it('preserves authoritative outcomes and requires explicit full-request mapping without replaying writes', async () => {
    type Row = { id: string; title: string; requiredSibling: string };
    const change: CellChange<Row> = {
        row: { id: 'a', title: 'Old', requiredSibling: 'Keep' },
        rowId: 'r:a',
        columnId: 'title',
        field: 'title',
        originalValue: 'Old',
        value: 'New',
        patch: { title: 'New' },
    };
    let outcome: SubmissionResult<void, Error> = { status: 'success' };
    const inputs: Row[] = [];
    const submit = formbraidSubmit({
        form: (_change: CellChange<Row>) => ({
            submission: {
                submit: (values: Row) => {
                    inputs.push(values);
                    return outcome;
                },
            },
        }),
        values: (event: CellChange<Row>) => ({ ...event.row, ...event.patch }),
    });
    expect(await submit(change)).toEqual({ status: 'accepted' });
    expect(inputs).toEqual([{ id: 'a', title: 'New', requiredSibling: 'Keep' }]);
    outcome = { status: 'failure', error: new MutationResultError('Malformed result') };
    expect(await submit(change)).toMatchObject({ status: 'accepted', warning: { committed: true } });
    outcome = { status: 'invalid', errors: { title: 'Invalid title' } };
    expect(await submit(change)).toEqual({ status: 'invalid', errors: { title: ['Invalid title'] } });
    outcome = { status: 'failure', error: Object.assign(new Error('Denied'), { details: { status: 403 } }) };
    expect(await submit(change)).toMatchObject({ status: 'rejected' });
    outcome = { status: 'failure', error: new Error('Disconnected') };
    expect(await submit(change)).toMatchObject({ status: 'unconfirmed' });
    expect(inputs).toHaveLength(5);
});

it('derives supported scalar validation and leaves query-backed fields to the shared relation adapter', () => {
    const field = {
        name: 'title',
        label: 'Title',
        type: 'string',
        component: 'TextField',
        props: {},
        nullable: false,
        serverRules: [],
        constraints: { required: true, maxLength: 4 },
    } as const;
    const editor = editorFromForm(field)!;
    expect(parseEditorValue(editor, 'four').errors).toEqual([]);
    expect(parseEditorValue(editor, 'longer').errors).toEqual(['The value is too long.']);
    expect(parseEditorValue(editor, '').errors).toEqual(['This field is required.']);
    expect(
        editorFromForm({ ...field, options: { kind: 'source', id: 'lists', inputs: [], resolveInputs: [] } }),
    ).toBeUndefined();
});
