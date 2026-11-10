import { router } from '@inertiajs/react';
import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import { toast } from 'sonner';
import { dashboard } from '@/wayfinder/routes';
import { bindings, navigation } from './generated.queries';

export const livePages = createInertiaAdapter(bindings, {
    navigation,
    url: { dashboard: { lists: { status: 'status' } } },
    access: {
        onRevoked: () => {
            router.visit(dashboard().url);
            toast.error('This list is no longer available.');
        },
    },
});

export const Link = livePages.Link;
