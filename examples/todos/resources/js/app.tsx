import { createInertiaApp } from '@inertiajs/react';
import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import type { ComponentType } from 'react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { bindings } from '@/zero/generated.queries';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = import.meta.glob<{ default: ComponentType }>('./pages/**/*.tsx');
const livePages = createInertiaAdapter(bindings, {
    url: { dashboard: { lists: { status: 'status' } } },
});

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    resolve: async (name, page) => {
        const module = await pages[`./pages/${name}.tsx`]();
        return livePages.wrap(module.default, page);
    },
    layout: (name) => {
        switch (true) {
            case name === 'welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
