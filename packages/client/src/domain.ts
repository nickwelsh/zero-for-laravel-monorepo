import {
    defineMutator,
    defineMutators,
    type AnyMutatorRegistry,
    type Mutator,
    type MutatorDefinition,
    type Schema,
    type Transaction,
    type MutateRequest,
} from '@rocicorp/zero';
import type { ClientContext } from './contract';
import { fail, freeze, keys, object, validateFormRules } from './validation';
import { createDomainForms } from './domain-form';
import {
    completeMutation,
    operationForm,
    MutationResultError,
    type MutationClient,
    type MutationFormOptions,
} from './operation';

export interface DomainField {
    readonly type: 'string' | 'number' | 'boolean';
    readonly optional?: boolean;
    readonly nullable?: boolean;
    readonly array?: boolean;
    readonly maxItems?: number;
}
export type DomainContracts = Readonly<Record<string, Readonly<Record<string, DomainField>>>>;
type Scalar<F extends DomainField> = F['type'] extends 'string'
    ? string
    : F['type'] extends 'boolean'
      ? boolean
      : number;
type Value<F extends DomainField> =
    | (F['array'] extends true ? readonly Scalar<F>[] : Scalar<F>)
    | (F['nullable'] extends true ? null : never);
export type DomainInput<D extends DomainContracts[string]> = {
    readonly [K in keyof D as D[K]['optional'] extends true ? never : K]: Value<D[K]>;
} & { readonly [K in keyof D as D[K]['optional'] extends true ? K : never]?: Value<D[K]> };
export type DomainOptimistic<S extends Schema, D extends DomainContracts> = {
    readonly [N in keyof D]?: (options: {
        readonly tx: Transaction<S>;
        readonly args: DomainInput<D[N]>;
        readonly ctx: ClientContext;
    }) => Promise<void>;
};

export type DomainResults = Readonly<Record<string, DomainContracts[string]>>;
export type DomainResult<R extends DomainResults, N extends string> = N extends keyof R ? DomainInput<R[N]> : void;
export interface DomainOptions<S extends Schema, D extends DomainContracts, R extends DomainResults> {
    readonly optimistic?: DomainOptimistic<S, D>;
    readonly formRules?: Readonly<Record<string, Readonly<Record<string, readonly string[]>>>>;
    readonly results?: R;
    readonly resultURL?: string;
}

function validateDomain(fields: DomainContracts[string], raw: unknown): Record<string, unknown> {
    const input = object(raw, 'input');
    keys(input, Object.keys(fields), 'input');
    for (const [field, spec] of Object.entries(fields)) {
        if (!(field in input)) {
            if (spec.optional) continue;
            fail(field, 'This field is required.');
        }
        const value = input[field];
        if (value === null && spec.nullable) continue;
        if (
            spec.array &&
            (!Array.isArray(value) ||
                !Number.isInteger(spec.maxItems) ||
                spec.maxItems! < 1 ||
                spec.maxItems! > 100 ||
                value.length > spec.maxItems!)
        )
            fail(field, 'Expected a bounded list.');
        for (const item of spec.array ? (value as unknown[]) : [value]) {
            if (
                typeof item !== spec.type ||
                (typeof item === 'number' && (!Number.isFinite(item) || Math.abs(item) > Number.MAX_SAFE_INTEGER)) ||
                (typeof item === 'string' && new TextEncoder().encode(item).length > 65536)
            )
                fail(field, 'Invalid domain input type.');
        }
    }
    return input;
}

async function readResult<Result>(
    url: string,
    input: unknown,
    fields: DomainContracts[string],
    signal?: AbortSignal,
): Promise<Result> {
    const endpoint = new URL(url, globalThis.location?.href ?? 'http://localhost');
    if (globalThis.location && endpoint.origin !== globalThis.location.origin)
        throw new Error('Mutation results require a same-origin endpoint.');
    const token = globalThis.document?.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(token ? { 'X-XSRF-TOKEN': decodeURIComponent(token) } : {}),
        },
        body: JSON.stringify(input),
        ...(signal ? { signal } : {}),
    });
    if (!response.ok) throw new Error('The mutation result is unavailable.');
    return validateDomain(fields, await response.json()) as Result;
}

export class DomainOperation<
    S extends Schema,
    D extends DomainContracts,
    N extends keyof D & string,
    R extends DomainResults,
> {
    constructor(
        readonly name: N,
        private readonly contracts: D,
        private readonly mutator: Mutator<DomainInput<D[N]>, S, ClientContext>,
        private readonly options: DomainOptions<S, D, R>,
    ) {}
    get form() {
        return { definition: createDomainForms(this.contracts, this.options.formRules ?? {})[this.name] };
    }
    prepare(input: DomainInput<D[N]>): MutateRequest<DomainInput<D[N]>, S, ClientContext> {
        const args = freeze(structuredClone(input));
        validateDomain(this.contracts[this.name]!, args);
        return this.mutator(args) as unknown as MutateRequest<DomainInput<D[N]>, S, ClientContext>;
    }
    async resolveResult(input: DomainInput<D[N]>, options: { signal?: AbortSignal } = {}): Promise<DomainResult<R, N>> {
        const fields = this.options.results?.[this.name];
        if (!fields) return undefined as DomainResult<R, N>;
        if (!this.options.resultURL) throw new Error('The mutation result endpoint is missing.');
        return readResult(`${this.options.resultURL}/${encodeURIComponent(this.name)}`, input, fields, options.signal);
    }
    async execute(
        client: MutationClient<S>,
        input: DomainInput<D[N]>,
        options: { signal?: AbortSignal } = {},
    ): Promise<DomainResult<R, N>> {
        options.signal?.throwIfAborted();
        const request = this.prepare(input);
        await completeMutation(client.mutate(request), options.signal);
        try {
            return await this.resolveResult(request.args, options);
        } catch (error) {
            if (options.signal?.aborted) throw error;
            throw new MutationResultError(error);
        }
    }
    createForm(client: MutationClient<S>, options: MutationFormOptions<Partial<DomainInput<D[N]>>> = {}) {
        const generated = this.form.definition;
        return operationForm(
            generated,
            async (values, signal) => {
                const columns = Object.fromEntries(
                    Object.entries(this.contracts[this.name]!).map(([key, spec]) => [
                        key,
                        {
                            type: spec.array ? ('json' as const) : spec.type,
                            nullable: spec.nullable ?? false,
                            date: false as const,
                            enum: null,
                            hasDefault: false,
                            default: null,
                        },
                    ]),
                );
                validateFormRules(this.options.formRules?.[this.name] ?? {}, columns, values);
                return this.execute(client, values as DomainInput<D[N]>, { signal });
            },
            options,
        );
    }
}

/** Named business operations share native replay, generated forms and execution with model CRUD. */
export function createDomainMutations<
    S extends Schema,
    B extends AnyMutatorRegistry,
    const D extends DomainContracts,
    const R extends DomainResults = {},
>(schema: S, base: B, contracts: D, options: DomainOptions<S, D, R> = {}) {
    if ('actions' in base) fail('actions', 'The actions namespace is reserved for domain mutations.');
    const operations: Record<string, MutatorDefinition<any, any, ClientContext>> = Object.create(null);
    for (const [name, fields] of Object.entries(contracts)) {
        operations[name] = defineMutator(
            async ({ tx, args, ctx }: { tx: Transaction<S>; args: DomainInput<D[string]>; ctx: ClientContext }) => {
                validateDomain(fields, args);
                await options.optimistic?.[name]?.({ tx, args, ctx });
            },
        );
    }
    const mutators = defineMutators(base as any, { actions: operations }) as unknown as B & {
        readonly actions: { readonly [N in keyof D]: Mutator<DomainInput<D[N]>, S, ClientContext> };
    };
    const actions = Object.fromEntries(
        Object.keys(contracts).map((name) => [
            name,
            () => new DomainOperation(name, contracts, mutators.actions[name]!, options),
        ]),
    ) as {
        readonly [N in keyof D & string]: () => DomainOperation<S, D, N, R>;
    };
    void schema;
    return { mutators, actions };
}
