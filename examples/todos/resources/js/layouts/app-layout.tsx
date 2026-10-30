import AppLayoutTemplate from '@/layouts/app/app-sidebar-layout';
import { usePage } from '@inertiajs/react';
import type { BreadcrumbItem } from '@/types';
import { TodosZeroProvider } from '@/zero/provider';

export default function AppLayout({
    breadcrumbs = [],
    children,
}: {
    breadcrumbs?: BreadcrumbItem[];
    children: React.ReactNode;
}) {
    const { auth } = usePage().props;
    return (
        <TodosZeroProvider userId={auth.user.id}>
            <AppLayoutTemplate breadcrumbs={breadcrumbs}>{children}</AppLayoutTemplate>
        </TodosZeroProvider>
    );
}
