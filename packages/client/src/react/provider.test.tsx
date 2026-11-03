// @vitest-environment jsdom
import { cleanup, render } from '@testing-library/react';
import { createSchema, defineMutators, string, table } from '@rocicorp/zero';
import type { ComponentProps, ReactNode } from 'react';
import { afterEach, expect, expectTypeOf, it, vi } from 'vite-plus/test';
import { InputError, type ContextContract } from '../index';
import { createZeroProvider, type ProviderProps } from './index';

const native = vi.hoisted(() => ({ provider: vi.fn() }));
vi.mock('@rocicorp/zero/react', () => ({
    ZeroProvider: (props: { children: ReactNode }) => {
        native.provider(props);
        return props.children;
    },
}));
afterEach(() => {
    cleanup();
    native.provider.mockClear();
});

const schema = createSchema({ tables: [table('tasks').columns({ id: string() }).primaryKey('id')] });
type Context = { readonly tenant: string; readonly actor: number | null };
const contract = {
    tenant: { type: 'string', nullable: false },
    actor: { type: 'int', nullable: true },
} as const satisfies ContextContract;
const mutators = defineMutators({});
const Provider = createZeroProvider<Context>(
    schema,
    mutators,
    { cacheURL: null, storageKey: 'application' },
    false,
    undefined,
    contract,
);

it('requires typed context and separate userID, without requiring user_id', () => {
    expectTypeOf<ComponentProps<typeof Provider>>().toEqualTypeOf<ProviderProps<Context>>();
    expectTypeOf<{ context: Context; children: ReactNode }>().not.toExtend<ComponentProps<typeof Provider>>();
    expectTypeOf<{ userID: string; children: ReactNode }>().not.toExtend<ComponentProps<typeof Provider>>();
    const { rerender } = render(
        <Provider userID="session" context={{ tenant: 'a', actor: null }}>
            ready
        </Provider>,
    );
    const first = native.provider.mock.lastCall![0];
    expect(first.userID).toBe('session');
    expect(first.context).toEqual({ actor: null, tenant: 'a' });
    expect(Object.isFrozen(first.context)).toBe(true);
    rerender(
        <Provider userID="session" context={{ actor: null, tenant: 'a' }}>
            ready
        </Provider>,
    );
    expect(native.provider.mock.lastCall![0].context).toBe(first.context);
    expect(native.provider.mock.lastCall![0].storageKey).toBe(first.storageKey);
    rerender(
        <Provider userID="session" context={{ actor: null, tenant: 'b' }}>
            ready
        </Provider>,
    );
    expect(native.provider.mock.lastCall![0].storageKey).not.toBe(first.storageKey);
    expect(JSON.parse(first.storageKey)).toEqual(['application', { actor: null, tenant: 'a' }]);
});

it('validates reflected contracts before passing context to native Zero', () => {
    for (const context of [{ tenant: 'a' }, { tenant: 'a', actor: 1.5 }, { tenant: 'a', actor: null, extra: true }]) {
        expect(() =>
            render(
                <Provider userID={null} context={context as Context}>
                    invalid
                </Provider>,
            ),
        ).toThrow(InputError);
    }
    expect(native.provider).not.toHaveBeenCalled();
});

it('does not let invalid scalars silently change JSON context identity without a contract', () => {
    const Unreflected = createZeroProvider(schema, mutators, { cacheURL: null });
    expect(() =>
        render(
            <Unreflected userID={null} context={{ number: Infinity }}>
                invalid
            </Unreflected>,
        ),
    ).toThrow(InputError);
    expect(native.provider).not.toHaveBeenCalled();
});
