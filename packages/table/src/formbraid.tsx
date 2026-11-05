/* oxlint-disable react/no-children-prop -- Source children are typed render callbacks, not ReactNode children. */
import type { FieldDefinition, SubmissionCallback, OptionInputReference, JsonValue } from '@formbraid/core';
import type { ResolvedOptionInput } from '@formbraid/react';
import type { ZeroOptionSourceAdapter } from '@nickwelsh/zero-for-laravel/react/formbraid';
import { MutationResultError } from '@nickwelsh/zero-for-laravel';
import { createElement, useState } from 'react';
import { editors, type CellChange, type CellSubmitResult, type Editor } from './index';
import type { CellEditorProps } from './react/cell';

/** Explicitly supply full-request values and a route-bound generated descriptor. */
export function formbraidSubmit<Row, Output, Result, Failure>(config: {
    form: (change: CellChange<Row>) => { submission: { submit: SubmissionCallback<Output, Result, Failure> } };
    values: (change: CellChange<Row>) => Output;
}): (change: CellChange<Row>) => Promise<CellSubmitResult> {
    return async (change) => {
        try {
            const result = await config.form(change).submission.submit(config.values(change), {
                signal: new AbortController().signal,
                onProgress: () => {},
            });
            if (result.status === 'success') return { status: 'accepted' };
            if (result.status === 'invalid')
                return {
                    status: 'invalid',
                    errors: Object.fromEntries(
                        Object.entries(result.errors).map(([field, errors]) => [
                            field,
                            typeof errors === 'string' ? [errors] : errors,
                        ]),
                    ),
                };
            if (result.status === 'failure' && result.error instanceof MutationResultError)
                return { status: 'accepted', warning: result.error };
            if (
                result.status === 'failure' &&
                result.error &&
                typeof result.error === 'object' &&
                'details' in result.error
            )
                return { status: 'rejected', error: asError(result.error) };
            return {
                status: 'unconfirmed',
                error: asError(
                    result.status === 'cancelled'
                        ? 'Stopped observing the write.'
                        : result.status === 'failure' || result.status === 'defect'
                          ? result.error
                          : 'Unknown submission result.',
                ),
            };
        } catch (error) {
            return { status: 'unconfirmed', error: asError(error) };
        }
    };
}
const asError = (error: unknown) => (error instanceof Error ? error : new Error(String(error)));

/** Derive supported scalar metadata. Required siblings still belong to the form. */
export function editorFromForm(field: FieldDefinition): Editor | undefined {
    const options = {
        nullable: field.nullable,
        validate: (value: unknown): readonly string[] => {
            const constraints = field.constraints;
            if (value === null) return constraints.required ? ['This field is required.'] : [];
            if (typeof value === 'string') {
                const length = Array.from(value).length;
                if (constraints.required && !length) return ['This field is required.'];
                if (constraints.minLength !== undefined && length < constraints.minLength)
                    return ['The value is too short.'];
                if (constraints.maxLength !== undefined && length > constraints.maxLength)
                    return ['The value is too long.'];
            }
            if (
                typeof value === 'number' &&
                ((field.type === 'integer' && !Number.isInteger(value)) ||
                    (constraints.min !== undefined && value < constraints.min) ||
                    (constraints.max !== undefined && value > constraints.max))
            )
                return ['The number is outside the allowed range.'];
            if (constraints.options && !constraints.options.includes(value as string | number))
                return ['Choose an allowed value.'];
            return [];
        },
    };
    if (field.options?.kind === 'source') return undefined;
    if (field.options?.kind === 'static') return editors.select(field.options.items, options);
    if (field.type === 'boolean') return editors.checkbox();
    if (field.type === 'number' || field.type === 'integer') return editors.number(options);
    if (field.type === 'date') return editors.date(options);
    if (field.type === 'string')
        return field.constraints.format === 'email' ? editors.email(options) : editors.text(options);
    return undefined;
}
export interface RelationSource {
    source: ZeroOptionSourceAdapter;
    inputs?: readonly OptionInputReference[];
    resolveInputs?: readonly OptionInputReference[];
    context?: Readonly<Record<string, JsonValue>>;
}
/** The same component source used by generated Formbraid forms, not another query engine. */
export function RelationEditor<Row>({ cell }: CellEditorProps<Row>) {
    const config = cell.column.editor?.source as RelationSource;
    const [search, setSearch] = useState('');
    const selected = typeof cell.draft === 'string' || typeof cell.draft === 'number' ? cell.draft : undefined;
    const enabled = cell.active && cell.editable && cell.status !== 'pending' && cell.status !== 'unconfirmed';
    const inputs = (references: readonly OptionInputReference[], resolve: boolean): ResolvedOptionInput[] =>
        references.map((reference) => {
            const value =
                reference.kind === 'value'
                    ? search
                    : reference.kind === 'context'
                      ? config.context?.[reference.key]
                      : reference.path === cell.column.field
                        ? resolve
                            ? selected
                            : cell.draft
                        : cell.row && (cell.row as Record<string, unknown>)[reference.path];
            return { reference, present: value !== undefined, value: value as JsonValue };
        });
    if (!config?.source) return <span role="alert">A shared relation choice source is required.</span>;
    return (
        <div>
            {createElement(config.source, {
                operation: 'resolve',
                enabled: cell.active && selected !== undefined,
                selectedValue: selected,
                inputs: inputs(config.resolveInputs ?? [{ kind: 'field', path: cell.column.field! }], true),
                children: (state) => (
                    <output>
                        {selected === undefined
                            ? 'Unassigned'
                            : state.status === 'complete'
                              ? state.item.label
                              : state.status === 'unavailable'
                                ? 'Selected option unavailable'
                                : state.status === 'error'
                                  ? 'Could not resolve selection'
                                  : 'Resolving selection…'}
                    </output>
                ),
            })}
            <input
                aria-label={`Search ${cell.column.header}`}
                value={search}
                disabled={!enabled}
                onChange={(event) => setSearch(event.target.value)}
                onKeyDown={(event) => {
                    if (event.key === 'Enter') event.stopPropagation();
                }}
            />
            {createElement(config.source, {
                operation: 'list',
                enabled,
                inputs: inputs(config.inputs ?? [{ kind: 'value' }], false),
                children: (state) => (
                    <>
                        <select
                            aria-label={cell.column.header}
                            disabled={!enabled || state.status !== 'complete'}
                            value={
                                selected !== undefined && !state.items.some((option) => option.value === selected)
                                    ? -2
                                    : state.items.findIndex((option) => option.value === cell.draft)
                            }
                            onChange={(event) =>
                                cell.setDraft(
                                    event.target.value === '-1' ? null : state.items[Number(event.target.value)]!.value,
                                )
                            }
                        >
                            <option value="-2" disabled>
                                Current selection (shown above)
                            </option>
                            <option value="-1" disabled={!cell.column.editor?.nullable}>
                                {cell.column.editor?.nullable ? 'Unassigned' : 'Choose…'}
                            </option>
                            {state.items.map((option, index) => (
                                <option key={index} value={index} disabled={option.disabled}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {state.status === 'pending' && <output>Loading choices…</output>}
                        {state.status === 'error' && <span role="alert">Could not load choices.</span>}
                        {state.hasMore && (
                            <button type="button" onClick={() => state.loadMore?.()}>
                                More choices
                            </button>
                        )}
                    </>
                ),
            })}
        </div>
    );
}
