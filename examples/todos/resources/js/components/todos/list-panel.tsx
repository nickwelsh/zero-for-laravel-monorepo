import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy as destroyList, update as updateList } from '@/zero/App/Http/Controllers/TodoListController';
import { __invoke as shareList } from '@/zero/App/Http/Controllers/ShareListController';
import type { Inertia } from '@/wayfinder/types';
import { ListTodos } from './list-todos';
import { commitMutation } from './mutations';

export type ListRow = Inertia.Pages.Dashboard['lists']['data'][number];

export function ListPanel({ list, userId }: { list: ListRow; userId: number }) {
    const destroy = useControllerMutation(destroyList, {
        list: list.id,
    });
    const update = useControllerMutation(updateList, {
        list: list.id,
    });
    const share = useControllerMutation(shareList, {
        list: list.id,
    });
    const [email, setEmail] = useState('');
    const [renaming, setRenaming] = useState(false);
    const [name, setName] = useState(list.name);
    const owner = list.user_id === userId;

    return (
        <section aria-label={`${list.name} todos`} className="min-w-0 space-y-6 rounded-xl border bg-card p-5">
            <header className="flex items-start justify-between gap-3">
                <div>
                    <h2 className="text-xl font-semibold">{list.name}</h2>
                    <p className="mt-1 text-sm text-muted-foreground">
                        {owner ? 'Your list' : 'Shared with you'} · Everyone with access can edit todos.
                    </p>
                </div>
                {owner && (
                    <div className="flex gap-1">
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Rename list"
                            onClick={() => {
                                setName(list.name);
                                setRenaming(true);
                            }}
                        >
                            <Pencil className="size-4" />
                        </Button>
                        <Button
                            size="icon"
                            variant="ghost"
                            aria-label="Delete list"
                            disabled={destroy.state.status === 'pending'}
                            onClick={() => {
                                if (window.confirm(`Delete “${list.name}” and all its todos?`))
                                    void commitMutation(() => destroy({}));
                            }}
                        >
                            <Trash2 className="size-4" />
                        </Button>
                    </div>
                )}
            </header>
            {renaming && (
                <form
                    className="flex gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void commitMutation(
                            () => update({ name }),
                            () => setRenaming(false),
                        );
                    }}
                >
                    <Input
                        aria-label="List name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                        required
                        maxLength={100}
                    />
                    <Button disabled={update.state.status === 'pending'}>Save name</Button>
                    <Button type="button" variant="ghost" onClick={() => setRenaming(false)}>
                        Cancel
                    </Button>
                </form>
            )}
            <ListTodos listId={list.id} todos={list.todos} />
            {owner && (
                <div className="border-t pt-5">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            void commitMutation(
                                () => share({ email }),
                                () => {
                                    setEmail('');
                                    toast.success('List shared.');
                                },
                            );
                        }}
                    >
                        <div className="space-y-2">
                            <Label htmlFor={`share-${list.id}`}>Share with a registered user</Label>
                            <div className="flex gap-2">
                                <Input
                                    id={`share-${list.id}`}
                                    name="email"
                                    type="email"
                                    value={email}
                                    onChange={(event) => setEmail(event.target.value)}
                                    placeholder="friend@example.com"
                                    required
                                />
                                <Button variant="outline" disabled={share.state.status === 'pending'}>
                                    Share list
                                </Button>
                            </div>
                            <InputError message={share.state.fields.email?.join(' ')} />
                        </div>
                    </form>
                </div>
            )}
        </section>
    );
}
