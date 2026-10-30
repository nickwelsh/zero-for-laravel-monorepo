import { Head, usePage } from '@inertiajs/react';
import { TodosWorkspace } from '@/components/todos/todos-workspace';
import { dashboard } from '@/routes';
import type { Pages } from '@/zero/generated.queries';

export default function Dashboard({ lists }: Pages['App\\Http\\Controllers\\DashboardController@index']) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Todos" />
            <TodosWorkspace userId={auth.user.id} lists={lists} />
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Todos', href: dashboard() }] };
