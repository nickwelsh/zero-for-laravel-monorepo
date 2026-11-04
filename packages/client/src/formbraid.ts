import type { FieldDefinition, FormDefinition, FormDescriptor, GroupDefinition } from '@formbraid/core';
import type { Schema } from '@rocicorp/zero';
import type { ClientContext } from './contract';
import type {
    ControllerMutationAction,
    ControllerMutationBinding,
    ControllerMutationWire,
    MutationPreparation,
} from './controller-mutation';
import { completeMutation, MutationResultError, validationFields, type MutationClient } from './operation';

/** The generated application's connection and controller bindings, resolved only on submission. */
export interface ControllerFormRuntime<S extends Schema, C extends ClientContext> {
    client: MutationClient<S, C>;
    actions: Readonly<Record<string, unknown>>;
    preparation: MutationPreparation;
}

/** Generator primitive: produce a portable Formbraid descriptor, with no hook or editable state. */
export function createControllerForm<
    Input,
    Result,
    Values extends object,
    const Fields extends Readonly<Record<string, FieldDefinition>>,
    const Groups extends Readonly<Record<string, GroupDefinition>>,
    S extends Schema,
    C extends ClientContext,
    Services = never,
>(
    action: ControllerMutationAction<Input, Result>,
    definition: FormDefinition<Values, Fields, Groups, NoInfer<Input>, Services>,
    runtime: () => ControllerFormRuntime<S, C>,
    route: ControllerMutationWire['route'] = {},
): FormDescriptor<Values, Fields, Groups, Input, Result, Error, Services> {
    return {
        version: 1,
        definition,
        submission: {
            submit: async (values, { signal }) => {
                try {
                    signal.throwIfAborted();
                    const { client, actions, preparation } = runtime();
                    // Generation pairs the typed reference with this application's registered binding.
                    const binding = actions[action.action] as
                        | ControllerMutationBinding<Input, Result, S, C>
                        | undefined;
                    if (!binding) throw new Error('The controller action is not registered with this Zero provider.');
                    const input = action.inputSchema.parse(values);
                    const call = client.mutate(binding.prepare(input, route, preparation));
                    const raw = await completeMutation(call, signal);
                    try {
                        return { status: 'success', value: action.serverResultSchema.parse(raw) };
                    } catch (error) {
                        throw new MutationResultError(error);
                    }
                } catch (error) {
                    if (signal.aborted) return { status: 'cancelled' };
                    if (!(error instanceof MutationResultError)) {
                        const fields = validationFields(error);
                        if (Object.keys(fields).length > 0) return { status: 'invalid', errors: fields };
                    }
                    return { status: 'failure', error: error instanceof Error ? error : new Error(String(error)) };
                }
            },
        },
    };
}
