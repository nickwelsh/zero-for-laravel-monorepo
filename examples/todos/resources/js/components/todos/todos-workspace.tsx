import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store } from '@/zero/App/Http/Controllers/TodoListController';
import type { Inertia } from '@/wayfinder/types';
import { ListPanel } from './list-panel';
import { commitMutation } from './mutations';

export function TodosWorkspace({ userId, lists }: { userId: number; lists: Inertia.Pages.Dashboard['lists'] }) {
    const create = useControllerMutation(store);
    const [name, setName] = useState('');
    const [selectedId, setSelectedId] = useState<string | undefined>();
    const selected = lists.data.find((list) => list.id === selectedId) ?? lists.data[0];
    const online = lists.connection.name === 'connected';

    return (
        <div className="space-y-6 p-4 md:p-6">
            <header>
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h1 className="text-2xl font-semibold tracking-tight">Todos</h1>
                    <output className="text-sm text-muted-foreground">
                        {online ? 'Live sync connected' : 'Connecting to live sync…'}
                    </output>
                </div>
                <p className="mt-1 text-sm text-muted-foreground">A few things to do, together.</p>
            </header>
            {lists.error && (
                <div role="alert" className="rounded-lg border border-destructive p-3 text-sm text-destructive">
                    {lists.error.message}{' '}
                    <Button variant="link" onClick={() => lists.retry()}>
                        Retry
                    </Button>
                </div>
            )}
            <div className="grid items-start gap-5 md:grid-cols-[16rem_minmax(0,1fr)]">
                <aside aria-label="Todo lists" className="space-y-4 rounded-xl border bg-card p-4">
                    <h2 className="font-semibold">Your lists</h2>
                    <form
                        className="space-y-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void commitMutation(
                                () => create({ name }),
                                ({ id }) => {
                                    setName('');
                                    setSelectedId(id);
                                },
                            );
                        }}
                    >
                        <Input
                            aria-label="New list name"
                            placeholder="New list name"
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            required
                            maxLength={100}
                        />
                        <Button className="w-full" disabled={create.state.status === 'pending'}>
                            {create.state.status === 'pending' ? 'Creating…' : 'Create list'}
                        </Button>
                        <InputError message={create.state.fields.name?.join(' ')} />
                    </form>
                    {lists.status === 'loading' && <p className="text-sm text-muted-foreground">Loading lists…</p>}
                    <nav aria-label="Choose a list" className="space-y-1">
                        {lists.data.map((list) => (
                            <Button
                                key={list.id}
                                variant={list.id === selected?.id ? 'secondary' : 'ghost'}
                                className="w-full justify-start"
                                aria-current={list.id === selected?.id ? 'true' : undefined}
                                onClick={() => setSelectedId(list.id)}
                            >
                                <span className="truncate">{list.name}</span>
                                {list.user_id !== userId && (
                                    <span className="ml-auto text-xs text-muted-foreground">Shared</span>
                                )}
                            </Button>
                        ))}
                    </nav>
                    {lists.hasMore && (
                        <Button
                            variant="outline"
                            className="w-full"
                            onClick={() => lists.loadMore()}
                            disabled={lists.isLoadingMore}
                        >
                            Load more lists
                        </Button>
                    )}
                </aside>
                {selected ? (
                    <ListPanel key={selected.id} list={selected} userId={userId} />
                ) : (
                    <section className="rounded-xl border border-dashed p-10 text-center">
                        <h2 className="font-medium">Your next list starts here</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            Create a list, then invite someone to help with it.
                        </p>
                    </section>
                )}
            </div>
        </div>
    );
}
