<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Inertia\Inertia;
use Inertia\Response;

final class QueryController
{
    public function plain(): Response
    {
        return Inertia::render('tasks/plain', ['heading' => 'Unchanged', 'count' => 2]);
    }

    public function show(Task $task): Response
    {
        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function window(): Response
    {
        return Inertia::render('tasks/window', ['tasks' => Task::zeroQuery()->orderBy('title', 'desc')->infinite(2)]);
    }

    public function pages(): Response
    {
        return Inertia::render('tasks/pages', ['tasks' => Task::zeroQuery()->orderBy('created_at', 'desc')->paginate(2)]);
    }

    public function dated(DateQueryRequest $input): Response
    {
        $validated = $input->validated();

        return Inertia::render('tasks/dated', ['tasks' => Task::zeroQuery()->where('completed_at', '>=', $validated['after'])]);
    }

    public function search(QueryRequest $input): Response
    {
        $validated = $input->validated();
        $query = Task::zeroQuery();
        if (isset($validated['title']) && $validated['title'] !== '') {
            $query = $query->where('title', 'LIKE', '%'.$validated['title'].'%');
        }

        return Inertia::render('tasks/search', ['tasks' => $query->orderBy('id', 'asc')->infinite(2)]);
    }

    public function index(string $title): Response
    {
        $query = Task::zeroQuery();
        if ($title !== '') {
            $query = $query->where('title', $title);
        }

        return Inertia::render('tasks/index', [
            'heading' => 'Tasks',
            'tasks' => $query->orderBy('id', 'asc'),
        ]);
    }

    public function unsafe(): Response
    {
        abort_unless(auth()->check(), 403);

        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()]);
    }

    public function filtered(string $title, bool $showDone, int $window): Response
    {
        $query = Task::zeroQuery();
        if (! $showDone) {
            $query = $query->where('done', false);
        }

        if ($title !== '') {
            $query = $query->where('title', 'LIKE', '%'.$title.'%');
        }

        return Inertia::render('tasks/filtered', ['tasks' => $query->orderBy('id', 'asc')->limit($window)]);
    }

    public function relationships(string $title): Response
    {
        return Inertia::render('tasks/relationships', [
            'tasks' => Task::zeroQuery()
                ->where(fn ($args) => $args->not($args->or(
                    $args->cmp('title', 'IN', [$title, 'hidden']),
                    $args->exists('comments', fn ($query) => $query->where('body', 'secret'), ['flip' => true]),
                )))
                ->whereExists('list', fn ($query) => $query->where('id', 'list-a'), ['scalar' => true])
                ->whereExists('comments', ['flip' => false])
                ->related('comments', fn ($query) => $query->where('body', '!=', $title)->orderBy('id', 'desc')->limit(2))
                ->related('list')
                ->orderBy('title', 'desc')->orderBy('id', 'asc')
                ->start(['title' => $title, 'id' => 'task-a'], ['inclusive' => false]),
        ]);
    }

    public function unsafeSort(string $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->orderBy('id', $direction)]);
    }

    public function sorted(QueryDirection $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->orderBy('title', $direction->value)]);
    }

    public function unsafeNullable(QueryRequest $input): Response
    {
        $validated = $input->validated();
        $query = Task::zeroQuery();
        if (array_key_exists('title', $validated)) {
            $query = $query->where('title', 'LIKE', '%'.$validated['title'].'%');
        }

        return Inertia::render('tasks/index', ['tasks' => $query]);
    }
}

enum QueryDirection: string
{
    case Ascending = 'asc';
    case Descending = 'desc';
}
