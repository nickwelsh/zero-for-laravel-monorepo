import type { Schema, Zero, MutatorResult, ReadonlyJSONValue } from '@rocicorp/zero';
import type { ClientContext } from './contract';
import type { FieldDefinition, FormDefinition, FormDescriptor, SubmissionResult } from './form-contract';
import { InputError } from './validation';

export type MutationClient<S extends Schema> = Pick<Zero<S, undefined, ClientContext>, 'mutate'>;
export interface MutationFormOptions<Values extends object> {
    readonly bind?: Partial<Values>;
    readonly defaultValues?: Partial<Values>;
    readonly only?: readonly (keyof Values & string)[];
    readonly fields?: Partial<Record<keyof Values, Partial<FieldDefinition>>>;
}
export type MutationSubmissionResult<Result> =
    | Exclude<SubmissionResult, { status: 'success' }>
    | {
          readonly status: 'success';
          readonly value:
              | { readonly resolved: true; readonly data: Result }
              | { readonly resolved: false; readonly error: MutationResultError };
      };
export type MutationForm<Values extends object, Result> = Omit<
    FormDescriptor<Values, Readonly<Record<string, FieldDefinition>>, Record<string, never>>,
    'submission'
> & {
    readonly submission: {
        submit(
            this: void,
            values: Values,
            context: { readonly signal: AbortSignal },
        ): Promise<MutationSubmissionResult<Result>>;
    };
};

/** Shared form surface for model CRUD and named business operations. */
export interface FormOperation<S extends Schema, Values extends object, Result> {
    createForm(client: MutationClient<S>, options?: MutationFormOptions<Values>): MutationForm<Values, Result>;
}

/** The write committed, but its native result failed its declared contract. */
export class MutationResultError extends Error {
    readonly committed = true;
    constructor(cause: unknown) {
        super('Saved successfully, but the result did not match its contract.', { cause });
        this.name = 'MutationResultError';
    }
}

export async function observeMutation<T>(promise: Promise<T>, signal?: AbortSignal): Promise<T> {
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

function submissionFailure(error: unknown): Exclude<SubmissionResult, { status: 'success' }> {
    if (error instanceof InputError) return { status: 'invalid', errors: error.fields };
    const details = error instanceof Error && 'details' in error ? error.details : undefined;
    if (details && typeof details === 'object' && 'fields' in details)
        return { status: 'invalid', errors: details.fields as Readonly<Record<string, string[]>> };
    return { status: 'failure', error };
}

/** Customize presentation while retaining generated constraints and one native submission lifecycle. */
export function operationForm<Values extends object, Result>(
    generated: FormDefinition<Values, Readonly<Record<string, FieldDefinition>>, Record<string, never>>,
    execute: (values: Values, signal: AbortSignal) => Promise<Result>,
    options: MutationFormOptions<Values> = {},
): MutationForm<Values, Result> {
    const fields = Object.fromEntries(
        Object.entries(generated.fields)
            .filter(
                ([name]) =>
                    !(name in (options.bind ?? {})) &&
                    (!options.only || options.only.includes(name as keyof Values & string)),
            )
            .map(([name, field]) => [
                name,
                {
                    ...field,
                    ...options.fields?.[name as keyof Values],
                    constraints: field.constraints,
                    serverRules: field.serverRules,
                },
            ]),
    );
    const defaults = { ...generated.defaultValues, ...options.defaultValues };
    const defaultValues = Object.fromEntries(
        Object.keys(fields).map((name) => [name, defaults[name as keyof Values]]),
    ) as Values;
    return {
        version: 1,
        definition: { ...generated, fields, defaultValues },
        submission: {
            async submit(values, { signal }) {
                if (signal.aborted) return { status: 'cancelled' };
                try {
                    const submitted = Object.fromEntries(
                        Object.keys(fields)
                            .filter((name) => name in values)
                            .map((name) => [name, values[name as keyof Values]]),
                    );
                    const data = await execute({ ...submitted, ...options.bind } as Values, signal);
                    if (signal.aborted) return { status: 'cancelled' };
                    return { status: 'success', value: { resolved: true, data } };
                } catch (error) {
                    if (signal.aborted) return { status: 'cancelled' };
                    if (error instanceof MutationResultError)
                        return { status: 'success', value: { resolved: false, error } };
                    return submissionFailure(error);
                }
            },
        },
    };
}
