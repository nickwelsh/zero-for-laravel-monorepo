import { toast } from 'sonner';

export async function commitMutation<Result>(
    action: () => Promise<Result>,
    onSuccess?: (result: Result) => void,
): Promise<void> {
    try {
        const result = await action();
        onSuccess?.(result);
    } catch (error) {
        toast.error(error instanceof Error ? error.message : 'Unable to save the change.');
    }
}
