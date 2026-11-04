import { useFieldContext, type FieldProps } from '@formbraid/react';
import { useId } from 'react';
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
