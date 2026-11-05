/** Portable editor metadata. Rendering and mutation execution belong to adapters. */
export interface Editor {
    readonly kind: 'text' | 'email' | 'number' | 'date' | 'checkbox' | 'select' | 'relation' | 'custom';
    readonly nullable?: boolean;
    readonly multiline?: boolean;
    readonly options?: readonly {
        readonly value: string | number;
        readonly label: string;
        readonly disabled?: boolean;
    }[];
    readonly source?: unknown;
    readonly validate?: (value: unknown) => readonly string[];
    readonly fields?: readonly string[];
    readonly patch?: (value: unknown) => Readonly<Record<string, unknown>>;
}
export interface ColumnDefinition<Row> {
    readonly id: string;
    readonly header: string;
    readonly field?: keyof Row & string;
    readonly value?: (row: Row) => unknown;
    readonly editor?: Editor;
    readonly query?: { readonly filter?: string; readonly sort?: string; readonly group?: string };
}
type BoundOptions = {
    readonly id?: string;
    readonly header?: string;
    readonly editor?: Editor;
    readonly query?: NonNullable<ColumnDefinition<unknown>['query']>;
};
function bound<F extends string, const Query extends NonNullable<BoundOptions['query']> = {}>(
    field: F,
    options: Omit<BoundOptions, 'query'> & { readonly query?: Query } = {},
) {
    return { ...options, id: options.id ?? field, header: options.header ?? field, field };
}
export const column = {
    text: bound,
    email: bound,
    number: bound,
    date: bound,
    boolean: bound,
    select: bound,
    relation: bound,
    display<Row>(id: string, options: { header?: string; value: (row: Row) => unknown }): ColumnDefinition<Row> {
        return { id, header: options.header ?? id, value: options.value };
    },
};
type EditorOptions = Pick<Editor, 'nullable' | 'validate'>;
export const editors = {
    text: (options: EditorOptions & Pick<Editor, 'multiline'> = {}): Editor => ({ kind: 'text', ...options }),
    email: (options: EditorOptions = {}): Editor => ({ kind: 'email', ...options }),
    number: (options: EditorOptions = {}): Editor => ({ kind: 'number', ...options }),
    date: (options: EditorOptions = {}): Editor => ({ kind: 'date', ...options }),
    checkbox: (): Editor => ({ kind: 'checkbox' }),
    select: (options: NonNullable<Editor['options']>, settings: EditorOptions = {}): Editor => ({
        kind: 'select',
        options,
        ...settings,
    }),
    relation: (source: unknown, settings: EditorOptions = {}): Editor => ({ kind: 'relation', source, ...settings }),
    custom: (options: EditorOptions & Pick<Editor, 'fields' | 'patch'> = {}): Editor => ({
        kind: 'custom',
        ...options,
    }),
};
export type CellChange<Row> = {
    [Field in keyof Row & string]: {
        row: Row;
        rowId: string;
        columnId: string;
        field: Field;
        originalValue: Row[Field];
        value: Row[Field];
        patch: Readonly<Partial<Row>>;
    };
}[keyof Row & string];
export type CellSubmitResult =
    | { status: 'accepted'; warning?: Error }
    | { status: 'invalid'; errors: Readonly<Record<string, readonly string[]>> }
    | { status: 'rejected' | 'unconfirmed'; error: Error };

/** Parse a draft once on submission. Validation does not imply server permission. */
export function parseEditorValue(editor: Editor, draft: unknown): { value: unknown; errors: readonly string[] } {
    let value = draft;
    const errors: string[] = [];
    if ((draft === '' || draft === null) && editor.nullable) value = null;
    else if (editor.kind === 'number') {
        value =
            typeof draft === 'number' ? draft : typeof draft === 'string' && draft.trim() !== '' ? Number(draft) : NaN;
        if (!Number.isFinite(value)) errors.push('Enter a finite number.');
    } else if (editor.kind === 'date') {
        value = draft instanceof Date ? draft : typeof draft === 'string' ? new Date(draft) : new Date(NaN);
        if (!Number.isFinite((value as Date).getTime())) errors.push('Enter a valid date.');
    } else if (editor.kind === 'checkbox') {
        if (typeof value !== 'boolean') errors.push('Choose true or false.');
    } else if (editor.kind === 'text' || editor.kind === 'email') {
        if (typeof value !== 'string') errors.push('Enter text.');
        else if (editor.kind === 'email' && (value.length > 254 || !/^[^\s@]+@[^\s@.]+(?:\.[^\s@.]+)+$/.test(value)))
            errors.push('Enter a valid email address.');
    } else if (
        editor.kind === 'select' &&
        !editor.options?.some((option) => option.value === value && !option.disabled)
    )
        errors.push('Choose an available option.');
    if (editor.validate && !errors.length) errors.push(...editor.validate(value));
    return { value, errors };
}
