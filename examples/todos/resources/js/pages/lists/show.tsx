import { Head, Link } from '@inertiajs/react';
import type { Data } from '@nickwelsh/zero-for-laravel/react';
import { dashboard } from '@/wayfinder/routes';
import type { TodoListRow } from '@/zero/generated';

export default function ShowList({ list }: { list: Data<TodoListRow | undefined> }) {
    return list.render({
        onIdle: () => null,
        onLoading: () => <output>Loading list…</output>,
        onRefreshing: (row) => <ListDetails list={row} />,
        onFailure: () => <p role="alert">This list is unavailable.</p>,
        onStale: () => <output>Checking access…</output>,
        onSuccess: (row) => <ListDetails list={row} />,
    });
}

function ListDetails({ list }: { list: TodoListRow | undefined }) {
    if (!list) return <output>Checking access…</output>;
    return (
        <main className="space-y-6 p-6">
            <Head title={list.name} />
            <Link href={dashboard()} className="underline">
                Back to lists
            </Link>
            <h1 className="text-2xl font-semibold">{list.name}</h1>
            <p>Access to this list is checked by the same Laravel policy used by the live dashboard.</p>
            <p className="text-sm text-muted-foreground">
                If access is removed, this page closes and returns you to your lists.
            </p>
        </main>
    );
}
