/* oxlint-disable import/no-named-as-default-member -- Existing Wayfinder pages use the default controller object. */
import { Form } from '@inertiajs/react';
import TodoController from '../../../examples/todos/resources/js/wayfinder/App/Http/Controllers/TodoController';

// Baseline: the migrated forms must keep this JSX and change only the imports above.
export function CreateTodoForm() {
    return (
        <Form {...TodoController.store.form()}>
            {({ errors, processing }) => (
                <>
                    <label>
                        Title <input name="title" />
                    </label>
                    {errors.title && <p role="alert">{errors.title}</p>}
                    <label>
                        <input type="checkbox" name="open_after_saving" value="1" /> Open after saving
                    </label>
                    <button disabled={processing}>Create todo</button>
                </>
            )}
        </Form>
    );
}

export function EditTodoForm({ todo }: { todo: { id: string; title: string } }) {
    return (
        <Form {...TodoController.update.form(todo.id)}>
            {({ errors, processing }) => (
                <>
                    <label>
                        Title <input name="title" defaultValue={todo.title} />
                    </label>
                    {errors.title && <p role="alert">{errors.title}</p>}
                    <button disabled={processing}>Save changes</button>
                </>
            )}
        </Form>
    );
}
