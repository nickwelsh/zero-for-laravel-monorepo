import { useFormContext } from '@formbraid/react';
import { Button } from '@/components/ui/button';

export function SubmitButton({
    label = 'Save',
    pendingLabel = 'Saving…',
    className,
}: {
    readonly label?: string;
    readonly pendingLabel?: string;
    readonly className?: string;
}) {
    const form = useFormContext();
    return (
        <form.Subscribe selector={(state) => state.isSubmitting}>
            {(pending) => (
                <Button type="submit" className={className} disabled={pending}>
                    {pending ? pendingLabel : label}
                </Button>
            )}
        </form.Subscribe>
    );
}

export function CreateListButton() {
    return <SubmitButton label="Create list" pendingLabel="Creating…" className="w-full" />;
}
