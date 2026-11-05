/* oxlint-disable jsx-a11y/prefer-tag-over-role -- Cells belong to an ARIA grid, not a native table. */
import { useLayoutEffect, useRef, useSyncExternalStore, type HTMLAttributes, type ReactNode } from 'react';
import type { Capabilities } from '../index';
import type { TableSession } from './index';
import { useSession } from './viewport';

export function useTableCell<Row, C extends Capabilities>(
    table: TableSession<Row, C>,
    rowId: string,
    columnId: string,
) {
    'use no memo';
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    return table.cell(rowId, columnId);
}
export interface CellEditorProps<Row = unknown> {
    cell: ReturnType<TableSession<Row>['cell']>;
}
/** A recovery surface that never renders protected row values or query errors. */
export function EditingStatus<Row, C extends Capabilities>({
    table: provided,
    ...props
}: Omit<HTMLAttributes<HTMLDivElement>, 'children'> & { table?: TableSession<Row, C> }) {
    'use no memo';
    const table = useSession(provided);
    useSyncExternalStore(table.subscribe, table.snapshot, table.snapshot);
    const visibility = table.editingVisibility;
    if (!visibility) return null;
    const messages = {
        loading: 'Checking edited row visibility.',
        pending: 'Saving. Checking edited row visibility.',
        error: 'Cannot check edited row visibility.',
        unavailable: 'The edited row is no longer visible.',
    };
    const outcomes = {
        accepted: 'Saved.',
        invalid: 'The write was invalid.',
        rejected: 'The write was rejected.',
        unconfirmed: 'Write outcome unconfirmed. Check the record before editing again.',
    };
    return (
        <div {...props} role={visibility.status === 'error' ? 'alert' : 'status'}>
            <span>
                {messages[visibility.status]} {visibility.outcome && outcomes[visibility.outcome]}
            </span>
            {visibility.status === 'error' && (
                <button type="button" onClick={visibility.retry}>
                    Retry visibility
                </button>
            )}
            <button type="button" disabled={!visibility.canCancel} onClick={visibility.cancel}>
                {visibility.status === 'unavailable' ? 'Dismiss' : 'Cancel edit'}
            </button>
        </div>
    );
}
function display(value: unknown): string {
    if (typeof value === 'string') return value;
    if (typeof value === 'number' || typeof value === 'boolean' || typeof value === 'bigint') return `${value}`;
    if (value === null || value === undefined) return '';
    return value instanceof Date ? value.toISOString() : (JSON.stringify(value) ?? '');
}
export function NativeEditor<Row>({ cell }: CellEditorProps<Row>) {
    const editor = cell.column.editor!;
    const disabled = !cell.editable || cell.status === 'pending' || cell.status === 'unconfirmed';
    const shared = { disabled, 'aria-label': cell.column.header, 'aria-invalid': Object.keys(cell.errors).length > 0 };
    if (editor.kind === 'select')
        return (
            <select
                {...shared}
                value={editor.options?.findIndex((option) => option.value === cell.draft) ?? -1}
                onChange={(event) =>
                    cell.setDraft(
                        event.target.value === '-1' ? null : editor.options![Number(event.target.value)]!.value,
                    )
                }
            >
                <option value="-1" disabled={!editor.nullable}>
                    {editor.nullable ? 'Unassigned' : 'Choose…'}
                </option>
                {editor.options?.map((option, index) => (
                    <option key={index} value={index} disabled={option.disabled}>
                        {option.label}
                    </option>
                ))}
            </select>
        );
    if (editor.kind === 'checkbox')
        return (
            <input
                {...shared}
                type="checkbox"
                checked={cell.draft === true}
                onChange={(event) => cell.setDraft(event.target.checked)}
            />
        );
    const value = cell.draft instanceof Date ? cell.draft.toISOString().slice(0, 10) : display(cell.draft);
    if (editor.multiline)
        return <textarea {...shared} value={value} onChange={(event) => cell.setDraft(event.target.value)} />;
    return (
        <input
            {...shared}
            type={editor.kind === 'number' || editor.kind === 'email' || editor.kind === 'date' ? editor.kind : 'text'}
            value={value}
            onChange={(event) => cell.setDraft(event.target.value)}
        />
    );
}
export function Cell<Row, C extends Capabilities>({
    table: provided,
    rowId,
    columnId,
    children,
    ...props
}: Omit<HTMLAttributes<HTMLDivElement>, 'children'> & {
    table?: TableSession<Row, C>;
    rowId: string;
    columnId: string;
    children?: ReactNode | ((cell: ReturnType<TableSession<Row>['cell']>) => ReactNode);
}) {
    const table = useSession(provided);
    const cell = useTableCell(table, rowId, columnId);
    const ref = useRef<HTMLDivElement>(null);
    const wasActive = useRef(false);
    useLayoutEffect(() => {
        if (cell.active && !wasActive.current)
            ref.current
                ?.querySelector<HTMLElement>('input,textarea,select,[contenteditable="true"]')
                ?.focus({ preventScroll: true });
        if (!cell.active && wasActive.current && document.activeElement === document.body)
            ref.current?.focus({ preventScroll: true });
        wasActive.current = cell.active;
    }, [cell.active]);
    const content = typeof children === 'function' ? children(cell) : children;
    return (
        <div
            {...props}
            ref={ref}
            role="gridcell"
            tabIndex={-1}
            // oxlint-disable-next-line jsx-a11y/role-supports-aria-props -- aria-readonly is supported by WAI-ARIA gridcell.
            aria-readonly={!cell.editable}
            aria-label={cell.column.header}
            aria-busy={cell.status === 'pending'}
            data-editing={cell.active || undefined}
            data-conflict={cell.conflict || undefined}
            data-column={columnId}
            onDoubleClick={(event) => {
                props.onDoubleClick?.(event);
                if (!event.defaultPrevented) cell.begin();
            }}
            onKeyDown={(event) => {
                props.onKeyDown?.(event);
                if (event.defaultPrevented || event.nativeEvent.isComposing) return;
                if (!cell.active && (event.key === 'Enter' || event.key === 'F2')) {
                    event.preventDefault();
                    event.stopPropagation();
                    cell.begin();
                } else if (cell.active && event.key === 'Escape') {
                    event.preventDefault();
                    event.stopPropagation();
                    cell.cancel();
                } else if (
                    cell.active &&
                    event.key === 'Enter' &&
                    !(event.target instanceof Element && event.target.closest('select,button,[role="combobox"]')) &&
                    (!cell.column.editor?.multiline || event.ctrlKey || event.metaKey)
                ) {
                    event.preventDefault();
                    event.stopPropagation();
                    void cell.submit();
                }
            }}
        >
            {content ??
                (cell.active ? (
                    <>
                        {table.renderEditor?.(cell) ?? <NativeEditor cell={cell} />}
                        <button
                            type="button"
                            disabled={!cell.editable || cell.status === 'pending' || cell.status === 'unconfirmed'}
                            onClick={() => void cell.submit()}
                        >
                            Save
                        </button>
                        <button type="button" disabled={cell.status === 'pending'} onClick={() => cell.cancel()}>
                            Cancel
                        </button>
                    </>
                ) : (
                    display(cell.value ?? '—')
                ))}
            {cell.conflict && <output>The live value changed. Your draft is unchanged.</output>}
            {Object.entries(cell.errors).flatMap(([field, errors]) =>
                errors.map((error, index) => (
                    <span role="alert" key={`${field}:${index}`}>
                        {error}
                    </span>
                )),
            )}
            {cell.error && (
                <span role="alert">
                    {cell.status === 'unconfirmed'
                        ? 'Outcome unconfirmed. Check the record before editing again. '
                        : ''}
                    {cell.error.message}
                </span>
            )}
            {cell.warning && <output>{cell.warning.message}</output>}
        </div>
    );
}
