import { router } from '@inertiajs/react';
import { mutationNavigators, type MutationVisit } from './form-action';
import type { NavigationRoute, PageBinding } from './inertia-navigation';

type Page = { component: string; props: Record<string, unknown>; sharedProps?: string[] };
type Destination = { url: URL; route: NavigationRoute; bindings?: Record<string, PageBinding> | undefined };

/** Only a submission made by this document owns a visit. Zero replays never enter here. */
export function installMutationNavigation(
    currentPage: () => Page | undefined,
    resolve: (name: string, parameters: Record<string, string | number>) => Destination | undefined,
) {
    if (typeof window === 'undefined') return () => {};
    let generation = 0;
    const depart = () => {
        generation++;
    };
    let ownSwap = '';
    const observe = ({ detail: { page } }: { detail: { page: { url: string; props: Record<string, unknown> } } }) => {
        const url = new URL(page.url, window.location.href);
        if (url.href === ownSwap) return;
        depart();
        const metadata = page.props['__zero'];
        if (
            metadata &&
            typeof metadata === 'object' &&
            'awaitingMutation' in metadata &&
            metadata.awaitingMutation === true
        ) {
            // A history entry outlived its submitting visit. Re-read it; never replay its write or draft.
            const owner = generation;
            queueMicrotask(() => {
                if (generation === owner && window.location.href === url.href) router.visit(url, { replace: true });
            });
        }
    };
    const listeners = [router.on('before', depart), router.on('navigate', observe), router.on('clientVisit', observe)];
    window.addEventListener('popstate', depart);
    function begin(): MutationVisit {
        const id = ++generation;
        const origin = window.location.href;
        const source = currentPage();
        let predicted: string | undefined;
        const owns = () =>
            generation === id && (window.location.href === (predicted ?? origin) || ownSwap === window.location.href);
        async function swap(url: string, component: string, props: Record<string, unknown>, replace: boolean) {
            if (id !== generation) return;
            ownSwap = url;
            const target = new URL(url);
            try {
                await new Promise<void>((done) =>
                    router[replace ? 'replace' : 'push']({
                        component,
                        url: target.pathname + target.search + target.hash,
                        props: { errors: {}, ...props },
                        onFinish: () => done(),
                    }),
                );
            } finally {
                if (ownSwap === url) ownSwap = '';
            }
        }
        return {
            owns,
            async apply(target, confirmed) {
                if (!owns()) return;
                if (target.kind === 'stay') {
                    if (confirmed && predicted && source) await swap(origin, source.component, source.props, true);
                    return;
                }
                const next = resolve(target.route, target.parameters);
                if (!next)
                    throw new Error(
                        `Cannot resolve mutation destination ${target.route}. Regenerate the Inertia routes.`,
                    );
                const { url, route, bindings } = next;
                if (url.origin !== window.location.origin)
                    throw new Error('Native mutations require a same-origin destination.');
                // Guarded live bindings re-enter Laravel's route and maintain access themselves.
                // An ordinary page has no such boundary, so confirm it with a real GET.
                if (
                    !source ||
                    !route.client ||
                    !route.component ||
                    !bindings ||
                    Object.values(bindings).some((b) => b.pending) ||
                    (confirmed && !Object.values(bindings).some((b) => b.access))
                ) {
                    if (confirmed) router.visit(url, { replace: predicted !== undefined });
                    return;
                }
                const replace = predicted !== undefined;
                const live = Object.keys(bindings).length > 0 && Object.values(bindings).every((b) => b.access);
                if (confirmed && live && predicted === url.href) return;
                predicted = url.href;
                const shared = Object.fromEntries(
                    (source.sharedProps ?? [])
                        .filter((k) => k !== '__zero' && k !== 'errors' && !Object.hasOwn(bindings, k))
                        .map((k) => [k, source.props[k]]),
                );
                await swap(
                    url.href,
                    route.component,
                    {
                        ...shared,
                        __zero: {
                            version: 1,
                            mode: 'replace',
                            bindings,
                            ...(!confirmed && !live ? { awaitingMutation: true } : {}),
                        },
                    },
                    replace,
                );
            },
            async recover(save) {
                if (!owns() || !predicted || !source) return;
                save();
                await swap(origin, source.component, source.props, true);
                predicted = undefined;
            },
            finish(committedWarning) {
                if (committedWarning && predicted && owns()) router.visit(predicted, { replace: true });
                if (id === generation) depart();
            },
        };
    }
    mutationNavigators.add(begin);
    return () => {
        depart();
        mutationNavigators.delete(begin);
        listeners.forEach((stop) => stop());
        window.removeEventListener('popstate', depart);
    };
}
