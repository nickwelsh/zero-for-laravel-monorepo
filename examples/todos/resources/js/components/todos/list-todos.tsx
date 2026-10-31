import { useControllerMutation } from '@nickwelsh/zero-for-laravel/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as create } from '@/zero/App/Http/Controllers/TodoController';
import type { ListRow } from './list-panel';
import { commitMutation } from './mutations';
import { TodoRow } from './todo-row';

export function ListTodos({ listId, todos }: { listId: string; todos: ListRow['todos'] }) {
    const createTodo = useControllerMutation(create);
    const [title, setTitle] = useState('');

    return (
        <div className="space-y-4">
            <form
                className="flex gap-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    void commitMutation(
                        () => createTodo({ list_id: listId, title }),
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
                <Button disabled={createTodo.state.status === 'pending'}>Add todo</Button>
            </form>
            <InputError message={createTodo.state.fields.title?.join(' ')} />
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
