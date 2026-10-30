import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { actions } from '@/zero/generated.react';
import type { ListRow } from './list-panel';
import { commitMutation } from './mutations';
import { TodoRow } from './todo-row';

export function ListTodos({ listId, todos }: { listId: string; todos: ListRow['todos'] }) {
    const create = useControllerMutation(actions['App\\Http\\Controllers\\TodoController@store']);
    const [title, setTitle] = useState('');

    return (
        <div className="space-y-4">
            <form
                className="flex gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void commitMutation(
                        () => create({ list_id: listId, title }),
                        () => setTitle(''),
                    );
                }}
            >
                <Input
                    aria-label="New todo"
                    placeholder="What needs doing?"
                    value={title}
                    onChange={(event) => setTitle(event.target.value)}
                    required
                    maxLength={200}
                />
                <Button disabled={create.state.status === 'pending'}>Add todo</Button>
            </form>
            <InputError message={create.state.fields.title?.join(' ')} />
            {todos.length === 0 && (
                <p className="py-8 text-center text-sm text-muted-foreground">
                    Nothing to do yet. Add the first todo above.
                </p>
            )}
            <ul className="space-y-2">
                {todos.map((todo) => (
                    <TodoRow key={todo.id} todo={todo} />
                ))}
            </ul>
        </div>
    );
}
