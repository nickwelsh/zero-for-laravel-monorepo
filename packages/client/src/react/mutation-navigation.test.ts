// @vitest-environment jsdom
import { router } from '@inertiajs/react';
import type { MutatorResult } from '@rocicorp/zero';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import { z } from 'zod';
import { controllerForm, mountFormSession } from './form-action';
import { installMutationNavigation } from './mutation-navigation';

const action = controllerForm(
    { action: 'Todo@store', inputSchema: z.object({ title: z.string() }), serverResultSchema: z.undefined() },
    [],
    { title: { kind: 'string' } },
);
const target = { kind: 'visit', route: 'todos.show', parameters: { todo: 'a' } } as const;
const origin = '/todos?sort=title&status=open';
const clear: (() => void)[] = [];
afterEach(() => {
    for (const stop of clear.splice(0)) stop();
    vi.restoreAllMocks();
});
function submit() {
    history.replaceState(null, '', origin);
    let acknowledge!: (result: Awaited<MutatorResult['server']>) => void;
    const server: MutatorResult['server'] = new Promise((resolve) => {
        acknowledge = resolve;
    });
    const unmount = mountFormSession({
        actions: { 'Todo@store': { navigateAfter: 'local' } },
        invoke: () => ({ client: Promise.resolve({ type: 'success', data: target }), server }),
    });
    clear.push(
        unmount,
        installMutationNavigation(
            () => ({ component: 'todos/index', props: {} }),
            () => ({
                route: {
                    name: 'todos.show',
                    uri: 'todos/{todo}',
                    route: 'show',
                    pattern: '',
                    protocol: null,
                    host: null,
                    component: 'todos/show',
                    client: true,
                    bindings: {},
                    defaults: {},
                },
                url: new URL('/todos/a', location.href),
                bindings: { todo: { args: {}, query: 'show', access: { status: 404 } } },
            }),
        ),
    );
    vi.spyOn(router, 'push').mockImplementation((params) => {
        history.pushState(null, '', params.url);
        params.onFinish?.(params);
    });
    const draft = new FormData();
    draft.set('title', 'Private draft');
    const result = action.form().action.submit({ title: 'Private draft' }, { draft });
    return { acknowledge, result, unmount };
}

it.each(['same user', 'different session'] as const)(
    'never restores a departed draft after rejection: %s',
    async (identity) => {
        const { acknowledge, result, unmount } = submit();
        await vi.waitFor(() => expect(location.pathname).toBe('/todos/a'));
        window.dispatchEvent(new Event('offline'));
        expect(location.pathname).toBe('/todos/a');
        // Leaving and returning to the very same URL must still revoke ownership.
        window.dispatchEvent(new PopStateEvent('popstate'));
        if (identity === 'different session') unmount();
        const notify = vi.fn();
        window.addEventListener('zero:mutation-error', notify);
        clear.push(() => window.removeEventListener('zero:mutation-error', notify));
        const replace = vi.spyOn(router, 'replace');
        acknowledge({ type: 'error', error: { type: 'app', message: 'Denied', details: { code: 'forbidden' } } });
        await expect(result).rejects.toThrow('Denied');
        expect(notify).toHaveBeenCalledTimes(identity === 'same user' ? 1 : 0);
        expect(replace).not.toHaveBeenCalled();
        history.replaceState(null, '', origin);
        expect(action.form().action.takeRecovery()).toBeUndefined();
    },
);
