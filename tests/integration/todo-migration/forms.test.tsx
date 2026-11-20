// @vitest-environment jsdom
import { router } from '@inertiajs/react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vite-plus/test';
import { CreateTodoForm, EditTodoForm } from './forms';

afterEach(cleanup);

it('submits the generated Wayfinder action and only successful named controls through Inertia', () => {
    const visits: unknown[] = [];
    const stop = router.on('before', ({ detail: { visit } }) => {
        visits.push([visit.method, visit.url.pathname + visit.url.search, visit.data]);
        return false; // Inspect the resolved visit before transport; Laravel tests exercise these requests.
    });
    try {
        const page = render(<CreateTodoForm />);
        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Keep filters' } });
        fireEvent.click(screen.getByRole('button', { name: 'Create todo' }));
        fireEvent.click(screen.getByLabelText('Open after saving'));
        fireEvent.click(screen.getByRole('button', { name: 'Create todo' }));
        page.rerender(<EditTodoForm todo={{ id: 'bound-id', title: 'Original' }} />);
        fireEvent.change(screen.getByLabelText('Title'), { target: { value: 'Edited' } });
        fireEvent.click(screen.getByRole('button', { name: 'Save changes' }));
        expect(visits).toEqual([
            ['post', '/todos', { title: 'Keep filters' }],
            ['post', '/todos', { title: 'Keep filters', open_after_saving: '1' }],
            ['post', '/todos/bound-id?_method=PATCH', { title: 'Edited' }],
        ]);
    } finally {
        stop();
    }
});
