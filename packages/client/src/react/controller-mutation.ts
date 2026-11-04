import type { Schema, Zero } from '@rocicorp/zero';
import { ZeroContext } from '@rocicorp/zero/react';
import { createContext, useContext, useEffect, useRef, useState } from 'react';
import type { ClientContext } from '../contract';
import type {
    ControllerMutationAction,
    ControllerMutationBinding,
    ControllerMutationWire,
    MutationPreparation,
} from '../controller-mutation';
import { completeMutation, MutationResultError, validationFields } from '../operation';
import { selectionKey } from '../validation';

export const ControllerMutations = createContext<{
    actions: Readonly<Record<string, unknown>>;
    preparation: MutationPreparation;
}>({ actions: {}, preparation: {} });
export interface ControllerMutationState {
    status: 'idle' | 'pending' | 'success' | 'failure' | 'result-error';
    locallyApplied: boolean;
    error: Error | undefined;
    fields: Readonly<Record<string, readonly string[]>>;
}
const idle: ControllerMutationState = { status: 'idle', locallyApplied: false, error: undefined, fields: {} };

/** One native submission. Disposing observation never cancels an enqueued write. */
export function useControllerMutation<Input, Result, S extends Schema, C extends ClientContext = ClientContext>(
    binding: ControllerMutationBinding<Input, Result, S, C> | ControllerMutationAction<Input, Result>,
    route: ControllerMutationWire['route'] = {},
    preparation?: MutationPreparation,
) {
    const zero = useContext(ZeroContext) as Zero<S, undefined, C> | undefined;
    const registered = useContext(ControllerMutations);
    const key = selectionKey(route);
    const identity = useRef({ zero, binding, key, version: 0, mounted: true });
    const [snapshot, setSnapshot] = useState({ identity: identity.current, state: idle });
    if (identity.current.zero !== zero || identity.current.binding !== binding || identity.current.key !== key)
        identity.current = { zero, binding, key, version: 0, mounted: true };
    const current = identity.current;
    useEffect(() => {
        current.mounted = true;
        return () => {
            current.mounted = false;
        };
    }, [current]);
    const submit = async (input: Input): Promise<Result> => {
        const version = ++current.version;
        let locallyApplied = false;
        let completed = false;
        const update = (state: ControllerMutationState) => {
            if (current.mounted && identity.current === current && current.version === version)
                setSnapshot({ identity: current, state });
        };
        update({ ...idle, status: 'pending' });
        try {
            if (!zero) throw new Error('Zero is still initializing.');
            // React context erases the application's schema; generated references and
            // the provider registry restore the same binding by controller identity.
            const action = ('action' in binding ? registered.actions[binding.action] : binding) as
                | ControllerMutationBinding<Input, Result, S, C>
                | undefined;
            if (!action) throw new Error('The controller action is not registered with this Zero provider.');
            const parsed = 'inputSchema' in binding ? binding.inputSchema.parse(input) : input;
            const call = zero.mutate(action.prepare(parsed, route, { ...registered.preparation, ...preparation }));
            void call.client.then(
                (result) => {
                    if (!completed && result.type === 'success') {
                        locallyApplied = true;
                        update({ ...idle, status: 'pending', locallyApplied });
                    }
                    return undefined;
                },
                () => {},
            );
            const raw = await completeMutation(call);
            completed = true;
            let result: Result;
            try {
                result = binding.serverResultSchema.parse(raw);
            } catch (error) {
                throw new MutationResultError(error);
            }
            update({ ...idle, status: 'success', locallyApplied });
            return result;
        } catch (error) {
            completed = true;
            update({
                status: error instanceof MutationResultError ? 'result-error' : 'failure',
                locallyApplied,
                error: error instanceof Error ? error : new Error(String(error)),
                fields: validationFields(error),
            });
            throw error;
        }
    };
    return Object.assign(submit, { state: snapshot.identity === current ? snapshot.state : idle });
}
