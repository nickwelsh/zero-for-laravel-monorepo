import { createContext, useCallback, useMemo, useState, type ContextType, type ReactNode } from 'react';
import { ZeroProvider as NativeProvider } from '@rocicorp/zero/react';
import type { AnyMutatorRegistry, Schema, ZeroOptions } from '@rocicorp/zero';
import type { ClientContext, ContextContract } from '../contract';
import { validateContext } from '../validation';
import { ControllerMutations } from './controller-mutation';

export type ProviderOptions<C extends ClientContext = ClientContext> = Omit<
    ZeroOptions<Schema, undefined, C>,
    'schema' | 'mutators' | 'userID' | 'context'
>;
export type ProviderProps<C extends ClientContext = ClientContext> = {
    userID: string | null;
    context: C;
    auth?: string | null | undefined;
    children: ReactNode;
};

export const StartingZero = createContext(false);

/** Bind generated contracts once; native Zero retains ownership of its lifecycle. */
export function createZeroProvider<C extends ClientContext = ClientContext>(
    schema: Schema,
    mutators: AnyMutatorRegistry,
    options: ProviderOptions<C>,
    inertiaShell = false,
    controllers: ContextType<typeof ControllerMutations> = { actions: {}, preparation: {} },
    contextContract?: ContextContract,
) {
    function Session({ userID, context, auth, children }: ProviderProps<C>) {
        const [ready, setReady] = useState(false);
        const initialized = useCallback(() => setReady(true), []);
        return (
            <>
                {inertiaShell && !ready && <StartingZero.Provider value={true}>{children}</StartingZero.Provider>}
                <NativeProvider<Schema, undefined, C>
                    {...options}
                    {...(auth === undefined ? {} : { auth })}
                    init={initialized}
                    schema={schema}
                    mutators={mutators}
                    userID={userID}
                    context={context}
                    storageKey={JSON.stringify([options.storageKey ?? '', context])}
                >
                    {children}
                </NativeProvider>
            </>
        );
    }
    return function ZeroProvider({ userID, context, auth, children }: ProviderProps<C>) {
        validateContext(context, contextContract);
        // oxlint-disable-next-line unicorn/no-array-sort -- The entries array is freshly owned by this function.
        const contextKey = JSON.stringify(Object.entries(context).sort(([a], [b]) => a.localeCompare(b)));
        // Equal scalar context is one identity, even when callers use inline objects.
        const stableContext = useMemo(
            () => Object.freeze(Object.fromEntries(JSON.parse(contextKey))) as C,
            [contextKey],
        );
        const identity = JSON.stringify([userID, contextKey]);
        return (
            <ControllerMutations.Provider value={controllers}>
                <Session
                    {...(auth === undefined ? {} : { auth })}
                    key={identity}
                    userID={userID}
                    context={stableContext}
                >
                    {children}
                </Session>
            </ControllerMutations.Provider>
        );
    };
}
