import {
    defineMutator,
    defineMutators,
    type AnyMutatorRegistry,
    type Mutator,
    type MutatorDefinition,
    type Schema,
    type Transaction,
} from '@rocicorp/zero';
import type { ClientContext } from './contract';
import { fail, freeze, keys, object } from './validation';

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

/** Adds named actions to the same native Zero registry. Server-only by default. */
export function createDomainMutations<S extends Schema, B extends AnyMutatorRegistry, const D extends DomainContracts>(
    schema: S,
    base: B,
    contracts: D,
    optimistic: DomainOptimistic<S, D> = {},
) {
    if ('actions' in base) fail('actions', 'The actions namespace is reserved for domain mutations.');
    const operations: Record<string, MutatorDefinition<any, any, ClientContext>> = Object.create(null);
    for (const [name, fields] of Object.entries(contracts)) {
        operations[name] = defineMutator(
            async ({ tx, args, ctx }: { tx: Transaction<S>; args: DomainInput<D[string]>; ctx: ClientContext }) => {
                const input = object(args, 'input');
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
                            (typeof item === 'number' &&
                                (!Number.isFinite(item) || Math.abs(item) > Number.MAX_SAFE_INTEGER)) ||
                            (typeof item === 'string' && new TextEncoder().encode(item).length > 65536)
                        )
                            fail(field, 'Invalid domain input type.');
                    }
                }
                await optimistic[name]?.({ tx, args, ctx });
            },
        );
    }
    const mutators = defineMutators(base as any, { actions: operations }) as unknown as B & {
        readonly actions: { readonly [N in keyof D]: Mutator<DomainInput<D[N]>, S, ClientContext> };
    };
    const actions = Object.fromEntries(
        Object.keys(contracts).map((name) => [
            name,
            (input: unknown) => {
                // Own the payload once. Replay only consumes this immutable input; any
                // generated IDs/timestamps must be captured by the caller before enqueueing.
                const args = freeze(structuredClone(input)) as DomainInput<D[string]>;
                return mutators.actions[name]!(args);
            },
        ]),
    ) as { readonly [N in keyof D]: (input: DomainInput<D[N]>) => ReturnType<(typeof mutators.actions)[N]> };
    void schema; // Schema is inferred for typed transaction callbacks.
    return { mutators, actions };
}
