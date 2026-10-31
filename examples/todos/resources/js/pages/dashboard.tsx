import { Head, usePage } from '@inertiajs/react';
import { TodosWorkspace } from '@/components/todos/todos-workspace';
import { dashboard } from '@/routes';

export default function Dashboard({ lists }: Inertia.Pages.Dashboard) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Todos" />
            <TodosWorkspace userId={auth.user.id} lists={lists} />
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Todos', href: dashboard() }] };
