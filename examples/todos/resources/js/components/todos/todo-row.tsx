import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { Pencil, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { actions } from '@/zero/generated.react';
import type { ListRow } from './list-panel';
import { commitMutation } from './mutations';

export function TodoRow({ todo }: { todo: ListRow['todos'][number] }) {
    const update = useControllerMutation(actions['App\\Http\\Controllers\\TodoController@update'], { todo: todo.id });
    const destroy = useControllerMutation(actions['App\\Http\\Controllers\\TodoController@destroy'], { todo: todo.id });
    const [editing, setEditing] = useState(false);
    const [title, setTitle] = useState(todo.title);
    const pending = update.state.status === 'pending' || destroy.state.status === 'pending';

    return (
        <li className="flex items-center gap-3 rounded-lg border p-3">
            <input
                type="checkbox"
                className="size-4 accent-primary"
                aria-label={`Complete ${todo.title}`}
                checked={todo.completed}
                disabled={pending}
                onChange={(event) => void commitMutation(() => update({ completed: event.target.checked }))}
            />
            {editing ? (
                <form
                    className="flex min-w-0 flex-1 gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        void commitMutation(
                            () => update({ title }),
                            () => setEditing(false),
                        );
                    }}
                >
                    <Input
                        aria-label="Edit todo title"
                        value={title}
                        onChange={(event) => setTitle(event.target.value)}
                        maxLength={200}
                        required
                    />
                    <Button size="sm" disabled={pending}>
                        Save
                    </Button>
                    <Button size="sm" type="button" variant="ghost" onClick={() => setEditing(false)}>
                        Cancel
                    </Button>
                </form>
            ) : (
                <span
                    className={`min-w-0 flex-1 break-words ${todo.completed ? 'text-muted-foreground line-through' : ''}`}
                >
                    {todo.title}
                </span>
            )}
            {!editing && (
                <Button
                    size="icon"
                    variant="ghost"
                    aria-label={`Edit ${todo.title}`}
                    disabled={pending}
                    onClick={() => {
                        setTitle(todo.title);
                        setEditing(true);
                    }}
                >
                    <Pencil className="size-4" />
                </Button>
            )}
            <Button
                size="icon"
                variant="ghost"
                aria-label={`Delete ${todo.title}`}
                disabled={pending}
                onClick={() => void commitMutation(() => destroy({}))}
            >
                <Trash2 className="size-4" />
            </Button>
        </li>
    );
}
