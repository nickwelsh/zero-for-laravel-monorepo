import type { Schema, Zero, MutatorResult, ReadonlyJSONValue } from '@rocicorp/zero';
import type { ClientContext } from './contract';

export type MutationClient<S extends Schema> = Pick<Zero<S, undefined, ClientContext>, 'mutate'>;

/** The write committed, but its native result failed its declared contract. */
export class MutationResultError extends Error {
    readonly committed = true;
    constructor(cause: unknown) {
        super('Saved successfully, but the result did not match its contract.', { cause });
        this.name = 'MutationResultError';
    }
}

/** Stop observing an enqueued write without undoing or resubmitting it. */
async function observeMutation<T>(promise: Promise<T>, signal?: AbortSignal): Promise<T> {
    if (!signal) return promise;
    signal.throwIfAborted();
    let abort!: () => void;
    const cancelled = new Promise<never>((_, reject) => {
        abort = () => reject(signal.reason ?? new DOMException('Cancelled', 'AbortError'));
        signal.addEventListener('abort', abort, { once: true });
    });
    try {
        return await Promise.race([promise, cancelled]);
    } finally {
        signal.removeEventListener('abort', abort);
    }
}

export async function completeMutation(
    call: MutatorResult,
    signal?: AbortSignal,
): Promise<ReadonlyJSONValue | undefined> {
    const result = await observeMutation(call.server, signal);
    if (result.type === 'error') {
        const error = new Error(result.error.message);
        if (result.error.type === 'app') Object.assign(error, { details: result.error.details });
        throw error;
    }
    return result.data;
}
