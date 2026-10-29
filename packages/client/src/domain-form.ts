import type { MutatorResult } from '@rocicorp/zero';
import { completeMutation, operationForm } from './operation';
export { completeMutation } from './operation';
import type { DomainContracts, DomainInput } from './domain';
import type { FieldDefinition, FormDefinition, FormDescriptor } from './form-contract';
import { fieldDefinition } from './form';

export function createDomainForms<const D extends DomainContracts>(
    contracts: D,
    rules: Readonly<Record<string, Readonly<Record<string, readonly string[]>>>>,
) {
    return Object.fromEntries(
        Object.entries(contracts).map(([name, fields]) => {
            const definitions: Record<string, FieldDefinition> = {};
            const defaults: Record<string, unknown> = {};
            for (const [key, spec] of Object.entries(fields)) {
                const column = {
                    type: spec.array ? ('json' as const) : spec.type,
                    nullable: spec.nullable ?? false,
                    date: false,
                    enum: null,
                    hasDefault: false,
                    default: null,
                    ...(spec.array ? { cast: 'array' as const } : {}),
                };
                const field = fieldDefinition(key, column, rules[name]?.[key] ?? [], !spec.optional);
                definitions[key] = spec.array
                    ? { ...field, constraints: { ...field.constraints, maxItems: spec.maxItems! } }
                    : field;
                if (!spec.optional)
                    defaults[key] = spec.array
                        ? []
                        : spec.nullable
                          ? null
                          : spec.type === 'boolean'
                            ? false
                            : spec.type === 'number'
                              ? ''
                              : '';
            }
            return [name, { id: `actions.${name}`, fields: definitions, defaultValues: defaults, groups: {} }];
        }),
    ) as {
        readonly [N in keyof D]: FormDefinition<
            Partial<DomainInput<D[N]>>,
            Readonly<Record<keyof D[N], FieldDefinition>>,
            Record<string, never>
        >;
    };
}
/** Low-level bridge for existing custom native mutators. */
export function nativeMutationForm<
    T extends object,
    Fields extends Readonly<Record<string, FieldDefinition>>,
    Groups extends Record<string, never>,
>(
    definition: FormDefinition<T, Fields, Groups>,
    run: (values: T) => MutatorResult,
): FormDescriptor<T, Readonly<Record<string, FieldDefinition>>, Record<string, never>> {
    return operationForm(definition, async (values, signal) => completeMutation(run(values), signal));
}
