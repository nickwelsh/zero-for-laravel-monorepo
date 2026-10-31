import { createContext, useCallback, useMemo, useState, type ContextType, type ReactNode } from 'react';
import { ZeroProvider as NativeProvider } from '@rocicorp/zero/react';
import type { AnyMutatorRegistry, Schema, ZeroOptions } from '@rocicorp/zero';
import type { ClientContext } from '../contract';
import { ControllerMutations } from './controller-mutation';

export type ProviderOptions = Omit<
    ZeroOptions<Schema, undefined, ClientContext>,
    'schema' | 'mutators' | 'userID' | 'context'
>;
export type ProviderProps = {
    userID: string | null;
    context: ClientContext;
    auth?: string | null | undefined;
    children: ReactNode;
};

export const StartingZero = createContext(false);

/** Bind generated contracts once; native Zero retains ownership of its lifecycle. */
export function createZeroProvider(
    schema: Schema,
    mutators: AnyMutatorRegistry,
    options: ProviderOptions,
    inertiaShell = false,
    controllers: ContextType<typeof ControllerMutations> = { actions: {}, preparation: {} },
) {
    function Session({ userID, context, auth, children }: ProviderProps) {
        const [ready, setReady] = useState(false);
        const initialized = useCallback(() => setReady(true), []);
        return (
            <>
                {inertiaShell && !ready && <StartingZero.Provider value={true}>{children}</StartingZero.Provider>}
                <NativeProvider<Schema, undefined, ClientContext>
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
    return function ZeroProvider({ userID, context, auth, children }: ProviderProps) {
        // oxlint-disable-next-line unicorn/no-array-sort -- The entries array is freshly owned by this function.
        const contextKey = JSON.stringify(Object.entries(context).sort(([a], [b]) => a.localeCompare(b)));
        // Equal scalar context is one identity, even when callers use inline objects.
        const stableContext = useMemo(
            () => Object.freeze(Object.fromEntries(JSON.parse(contextKey))) as ClientContext,
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
