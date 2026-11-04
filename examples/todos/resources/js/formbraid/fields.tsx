import { useFieldContext, useFieldOptions, type FieldProps } from '@formbraid/react';
import { useId, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';

export function TextField({
    label,
    constraints,
    placeholder,
    type = 'text',
}: FieldProps<string | null> & {
    readonly placeholder?: string;
    readonly type?: 'text' | 'email';
}) {
    const field = useFieldContext<string | null>();
    const errorId = useId();
    return (
        <div className="min-w-0 flex-1 space-y-2">
            <Input
                name={field.name}
                type={type}
                aria-label={label}
                aria-invalid={field.errors.length > 0}
                aria-describedby={field.errors.length > 0 ? errorId : undefined}
                placeholder={placeholder}
                value={field.value ?? ''}
                onChange={(event) => field.setValue(event.target.value)}
                onBlur={field.touch}
                required={constraints.required}
                maxLength={constraints.maxLength}
            />
            <InputError id={errorId} role="alert" message={field.errors.join(' ')} />
        </div>
    );
}

export function CheckboxField({ label }: FieldProps<boolean>) {
    const field = useFieldContext<boolean>();
    return (
        <label className="flex items-center gap-2">
            <input
                type="checkbox"
                name={field.name}
                checked={field.value}
                onChange={(event) => field.setValue(event.target.checked)}
                onBlur={field.touch}
            />
            {label}
            <InputError message={field.errors.join(' ')} />
        </label>
    );
}

export function HiddenField(_props: FieldProps<string | null>) {
    const field = useFieldContext<string | null>();
    return <input type="hidden" name={field.name} value={field.value ?? ''} />;
}

// WAI-ARIA editable combobox: the input keeps focus; listbox/options use aria-activedescendant.
/* oxlint-disable jsx-a11y/prefer-tag-over-role, jsx-a11y/no-noninteractive-element-to-interactive-role */
/** One query-agnostic control: Formbraid supplies options; the draft stores only an ID. */
export function ComboboxField({ label }: FieldProps<string | null>) {
    const field = useFieldContext<string | null>();
    const id = useId();
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState('');
    const [active, setActive] = useState(0);
    const { listing, selection } = useFieldOptions({ value, enabled: open });
    const selectedLabel = selection.status === 'complete' ? selection.item.label : '';
    const choose = (index: number) => {
        const item = listing.items[index];
        if (!item || item.disabled) return;
        field.setValue(String(item.value));
        field.touch();
        setOpen(false);
        setValue('');
    };
    return (
        <div
            className="relative max-w-md space-y-2"
            onBlur={(event) => {
                if (!event.currentTarget.contains(event.relatedTarget)) {
                    setOpen(false);
                    field.touch();
                }
            }}
        >
            <label htmlFor={id} className="text-sm font-medium">
                {label}
            </label>
            <div className="relative">
                <Input
                    id={id}
                    role="combobox"
                    aria-expanded={open}
                    aria-controls={`${id}-options`}
                    aria-autocomplete="list"
                    aria-activedescendant={open && listing.items[active] ? `${id}-option-${active}` : undefined}
                    aria-invalid={field.errors.length > 0}
                    aria-describedby={`${id}-status`}
                    value={open ? value : selectedLabel}
                    placeholder={selection.status === 'pending' ? 'Loading selection…' : 'Type to find an option…'}
                    onFocus={() => setOpen(true)}
                    onClick={() => setOpen(true)}
                    onChange={(event) => {
                        setValue(event.target.value);
                        setActive(0);
                        setOpen(true);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.preventDefault();
                            setOpen(false);
                        }
                        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                            event.preventDefault();
                            setOpen(true);
                            setActive((current) =>
                                Math.max(
                                    0,
                                    Math.min(listing.items.length - 1, current + (event.key === 'ArrowDown' ? 1 : -1)),
                                ),
                            );
                        }
                        if (event.key === 'Enter' && open) {
                            event.preventDefault();
                            choose(active);
                        }
                    }}
                />
                {open && (
                    <div className="absolute top-full z-20 mt-1 w-full rounded-md border bg-popover p-1 shadow-md">
                        <ul id={`${id}-options`} role="listbox" aria-label={label} className="max-h-52 overflow-auto">
                            {listing.items.map((item, index) => (
                                <li
                                    id={`${id}-option-${index}`}
                                    key={item.value}
                                    role="option"
                                    aria-selected={item.value === field.value}
                                    aria-disabled={item.disabled}
                                    className={`cursor-pointer rounded-sm px-3 py-2 text-sm ${index === active ? 'bg-accent' : ''}`}
                                    onPointerMove={() => setActive(index)}
                                    onPointerDown={(event) => {
                                        event.preventDefault();
                                        choose(index);
                                    }}
                                >
                                    {item.label}
                                </li>
                            ))}
                        </ul>
                        {listing.status === 'pending' && (
                            <p className="p-2 text-sm text-muted-foreground">Finding options…</p>
                        )}
                        {listing.status === 'complete' && listing.items.length === 0 && (
                            <p className="p-2 text-sm text-muted-foreground">No matching options.</p>
                        )}
                        {listing.status === 'error' && (
                            <p role="alert" className="p-2 text-sm text-destructive">
                                Unable to load options.
                            </p>
                        )}
                    </div>
                )}
            </div>
            <div id={`${id}-status`} className="text-xs text-muted-foreground" aria-live="polite">
                {selection.status === 'unavailable' && field.value
                    ? 'Selected option is unavailable.'
                    : selection.status === 'error'
                      ? 'Unable to load the selected option.'
                      : 'Options update live. Only the selected ID is submitted.'}
            </div>
            <InputError role="alert" message={field.errors.join(' ')} />
        </div>
    );
}
