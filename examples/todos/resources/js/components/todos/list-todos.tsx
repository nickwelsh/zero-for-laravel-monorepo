import { toast } from 'sonner';
import { Form, useForm } from '@/formbraid';
import { store } from '@/formbraid/zero/App/Http/Controllers/TodoController';
import type { ListRow } from './list-panel';
import { TodoRow } from './todo-row';

export function ListTodos({ listId, todos }: { listId: string; todos: ListRow['todos'] }) {
    const defaults = { ...store.definition.defaultValues, list_id: listId };
    // Formbraid's optional instance API is only needed here to clear an accepted draft.
    const instance = useForm(store, {
        defaultValues: defaults,
        onResult: (result) => {
            if (result.status === 'success' && !instance.form.getState().isDirty) instance.form.reset(defaults);
            if (result.status === 'failure') toast.error(result.error.message);
        },
    });

    return (
        <div className="space-y-4">
            <Form form={instance} className="flex items-start gap-2">
                {({ fields, components }) => (
                    <>
                        <fields.title />
                        <components.SubmitButton label="Add todo" pendingLabel="Adding…" />
                    </>
                )}
            </Form>
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
