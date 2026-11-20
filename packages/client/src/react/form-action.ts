import type { MutatorResult } from '@rocicorp/zero';
import type { ControllerMutationAction, ControllerMutationWire, MutationDestination } from '../controller-mutation';
import { completeMutation, MutationResultError } from '../operation';
import { selectionKey } from '../validation';

type Input = Record<string, unknown>;
type Route = ControllerMutationWire['route'];
type Draft = { draft: FormData };
type Recovery = Draft & { error: unknown };
export interface NativeFormAction {
    key: string;
    normalize(data: Input): Input;
    submit(data: Input, recovery?: Draft): Promise<unknown>;
    takeRecovery(): Recovery | undefined;
}

export interface MutationVisit {
    owns(): boolean;
    apply(target: MutationDestination, confirmed: boolean): Promise<void>;
    recover(save: () => void): Promise<void>;
    finish(): void;
}
export const mutationNavigators = new Set<() => MutationVisit>();

interface Session {
    actions: Readonly<Record<string, unknown>>;
    invoke(action: string, input: unknown, route: Route): MutatorResult;
}
const sessions = new Set<Session>();
const recoveries = new WeakMap<Session, { url: string; key: string; value: Recovery }>();
export function mountFormSession(session: Session) {
    sessions.add(session);
    return () => {
        recoveries.delete(session);
        sessions.delete(session);
    };
}

type Field = { kind: string; nullable?: boolean; fields?: Record<string, Field>; item?: Field; required?: boolean };
function normalize(value: unknown, field: Field, path: string): unknown {
    if (typeof value === 'string') {
        if (field.required && value.trim() === '') {
            throw Object.assign(new Error(`The ${path} field is required.`), {
                details: { fields: { [path]: [`The ${path} field is required.`] } },
            });
        }
        if (value === '' && field.nullable) return null;
        if (field.kind === 'bool') {
            if (value === '1' || value === 'on') return true;
            if (value === '0') return false;
        }
        if ((field.kind === 'int' || field.kind === 'number') && value.trim() !== '' && Number.isFinite(Number(value)))
            return Number(value);
        if (field.kind === 'date' || field.kind === 'dateOnly') return new Date(value);
    }
    if (Array.isArray(value) && field.item) return value.map((v, i) => normalize(v, field.item!, `${path}.${i}`));
    if (value && typeof value === 'object' && field.fields)
        return Object.fromEntries(
            Object.entries(value).map(([k, v]) => [
                k,
                Object.hasOwn(field.fields!, k) ? normalize(v, field.fields![k]!, path ? `${path}.${k}` : k) : v,
            ]),
        );
    return value;
}

type Scalar = string | number;
type RouteField = readonly [string, string];
type Argument<F extends RouteField> = Scalar | Record<F[1], Scalar>;
type Named<F extends readonly RouteField[]> = { [K in F[number] as K[0]]: Argument<K> };
type RouteArgs<F extends readonly RouteField[]> = F extends readonly []
    ? []
    : F extends readonly [infer K extends RouteField]
      ? [Argument<K> | Named<F>]
      : [Named<F>];

function boundRoute(fields: readonly RouteField[], arg: unknown): Route {
    return Object.fromEntries(
        fields.map(([name, key]) => {
            let value = arg;
            if (value && typeof value === 'object' && Object.hasOwn(value, name)) value = Reflect.get(value, name);
            if (value && typeof value === 'object' && Object.hasOwn(value, key)) value = Reflect.get(value, key);
            if (typeof value !== 'string' && typeof value !== 'number')
                throw new Error(`Missing route argument ${name}.`);
            return [name, value];
        }),
    );
}

function destination(raw: unknown): MutationDestination {
    if (!raw || typeof raw !== 'object' || !('kind' in raw)) throw new Error('Invalid mutation navigation result.');
    if (raw.kind === 'stay') return { kind: 'stay' };
    if (
        raw.kind !== 'visit' ||
        !('route' in raw) ||
        typeof raw.route !== 'string' ||
        !('parameters' in raw) ||
        !raw.parameters ||
        typeof raw.parameters !== 'object' ||
        Array.isArray(raw.parameters) ||
        Object.values(raw.parameters).some((v) => typeof v !== 'string' && typeof v !== 'number')
    )
        throw new Error('Invalid mutation destination.');
    return raw as MutationDestination;
}

/** Attach the two-import form surface without importing React or Inertia in headless applications. */
export function controllerForm<
    const A extends ControllerMutationAction<Input, unknown>,
    const F extends readonly RouteField[],
>(reference: A, fields: F, inputs: Record<string, Field>) {
    type I = ReturnType<A['inputSchema']['parse']>;
    type R = ReturnType<A['serverResultSchema']['parse']>;
    function active() {
        const matching = [...sessions].filter((s) => Object.hasOwn(s.actions, reference.action));
        return matching.length === 1 ? matching[0] : undefined;
    }
    function descriptor(arg?: unknown) {
        const route = boundRoute(fields, arg);
        const key = selectionKey([reference.action, route]);
        const action: NativeFormAction = {
            key,
            normalize: (data) => normalize(data, { kind: 'object', fields: inputs }, '') as Input,
            takeRecovery() {
                const session = active();
                const recovery = session && recoveries.get(session);
                if (!session || !recovery || recovery.key !== key || recovery.url !== window.location.href)
                    return undefined;
                recoveries.delete(session);
                return recovery.value;
            },
            async submit(input, draft) {
                const session = active();
                if (!session)
                    throw new Error('A native controller form requires one active Zero provider for its action.');
                const binding = session.actions[reference.action] as { navigateAfter?: 'local' | 'server' };
                const nav = mutationNavigators.size === 1 ? [...mutationNavigators][0]!() : undefined;
                const origin = window.location.href;
                recoveries.delete(session);
                let local: Promise<void> = Promise.resolve();
                let settled = false;
                let committed = false;
                try {
                    if (binding.navigateAfter && !nav)
                        throw new Error('Redirecting native forms require one active Zero Inertia adapter.');
                    const call = session.invoke(reference.action, reference.inputSchema.parse(input), route);
                    if (binding.navigateAfter === 'local')
                        local = call.client
                            .then(async (result) => {
                                if (
                                    !settled &&
                                    active() === session &&
                                    nav?.owns() &&
                                    result.type === 'success' &&
                                    result.data !== undefined
                                )
                                    await nav.apply(destination(result.data), false);
                                return undefined;
                            })
                            .catch(() => {
                                /* An unavailable local prediction never substitutes for the server decision. */
                            });
                    const raw = await completeMutation(call);
                    committed = true;
                    settled = true;
                    await local;
                    if (binding.navigateAfter) {
                        const target = destination(raw);
                        if (active() === session && nav?.owns()) await nav.apply(target, true);
                        return reference.serverResultSchema.parse(undefined);
                    }
                    return reference.serverResultSchema.parse(raw);
                } catch (cause) {
                    settled = true;
                    await local;
                    const error = committed ? new MutationResultError(cause) : cause;
                    if (active() === session) {
                        if (!committed && nav?.owns())
                            await nav.recover(() => {
                                if (draft) recoveries.set(session, { url: origin, key, value: { ...draft, error } });
                            });
                        if (!draft || !nav?.owns() || committed)
                            window.dispatchEvent(
                                new CustomEvent('zero:mutation-error', { detail: { action: reference.action, error } }),
                            );
                    }
                    throw error;
                } finally {
                    nav?.finish();
                }
            },
        };
        return { action };
    }
    return Object.assign(reference, {
        form: (...args: RouteArgs<F>) => descriptor(args[0]),
        mutate: (...args: [...RouteArgs<F>, ...(keyof I extends never ? [] : [input: I])]): Promise<R> =>
            descriptor(fields.length ? args[0] : undefined).action.submit(
                ((fields.length ? args[1] : args[0]) as Input) ?? {},
            ) as Promise<R>,
    });
}
