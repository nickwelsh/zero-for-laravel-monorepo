import { createInertiaApp, Link, usePage } from '@inertiajs/react';
import { createInertiaAdapter } from '@nickwelsh/zero-for-laravel/inertia';
import { useLayoutEffect } from 'react';
import { bindings, navigation } from './generated.queries';
import { ZeroProvider } from './generated.react';
import TodoController from './TodoMigration/TodoController';
import { CreateTodoForm, EditTodoForm } from './forms';

const pages = createInertiaAdapter(bindings, { navigation });
const filtered = '/todos?status=open&sort=title';

function Layout({ children }) {
    const { url } = usePage();
    return (
        <ZeroProvider userID="alice" context={{ user_id: 'alice' }}>
            <header>
                <strong>Todo notebook</strong>
                <nav>
                    <Link href={filtered}>Todos</Link> · <Link href="/notes">Notes</Link>
                </nav>
            </header>
            <main>
                <small>{url}</small>
                {children}
            </main>
        </ZeroProvider>
    );
}
function Index({ todos }) {
    return (
        <>
            <h1>My todos</h1>
            <CreateTodoForm />
            <ul>
                {todos.data.map((todo) => (
                    <li key={todo.id}>{todo.title}</li>
                ))}
            </ul>
        </>
    );
}
function Show({ todo }) {
    useLayoutEffect(() => {
        window.todoShowMounts++;
    }, []);
    if (!todo.data) return <p>Todo removed.</p>;
    return (
        <section data-private data-created={todo.data.created_at?.toISOString()} data-complete={todo.complete}>
            <h1>{todo.data.title}</h1>
            <EditTodoForm todo={todo.data} />
            <button type="button" onClick={() => void TodoController.destroy.mutate(todo.data.id)}>
                Delete todo
            </button>
        </section>
    );
}
function Notes({ activity }) {
    return (
        <>
            <h1>My notes</h1>
            <h2>Recent todos</h2>
            <ul>
                {activity.data.map((todo) => (
                    <li key={todo.id}>{todo.title}</li>
                ))}
            </ul>
        </>
    );
}
window.todoShowMounts = 0;
const components = { 'todos/index': Index, 'todos/show': Show, notes: Notes };
void createInertiaApp({
    resolve: (name, page) => pages.wrap(components[name], page),
    layout: () => Layout,
    strictMode: false,
});
