import { Head, usePage } from '@inertiajs/react';
import type { Data } from '@nickwelsh/zero-for-laravel/react';
import type { InferLiveQueryResult } from '@nickwelsh/zero-for-laravel';
import { TodosWorkspace } from '@/components/todos/todos-workspace';
import { dashboard } from '@/routes';
import type { index } from '@/zero/App/Http/Controllers/DashboardController';

export default function Dashboard({ lists }: { lists: Data<InferLiveQueryResult<typeof index.lists>> }) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Todos" />
            <TodosWorkspace userId={auth.user.id} lists={lists} />
        </>
    );
}

Dashboard.layout = { breadcrumbs: [{ title: 'Todos', href: dashboard() }] };
