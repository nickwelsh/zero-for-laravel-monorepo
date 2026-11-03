<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        Gate::authorize('view', $task);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function window(Request $request): Response
    {
        return Inertia::render('tasks/window', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->orderBy('title', 'desc')->infinite(2)]);
    }

    public function pages(Request $request): Response
    {
        return Inertia::render('tasks/pages', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->orderBy('created_at', 'desc')->paginate(2)]);
    }

    public function dated(DateQueryRequest $input): Response
    {
        $validated = $input->validated();

        return Inertia::render('tasks/dated', ['tasks' => Task::zeroQuery()->visibleTo($input->user())->where('completed_at', '>=', $validated['after'])]);
    }

    public function choices(ChoiceQueryRequest $input): Response
    {
        return Inertia::render('tasks/choices', ['tasks' => Task::zeroQuery()->visibleTo($input->user())->orderBy('id', 'asc')->infinite(2)]);
    }

    public function search(QueryRequest $input): Response
    {
        $validated = $input->validated();
        $query = Task::zeroQuery()->visibleTo($input->user());
        if (isset($validated['title']) && $validated['title'] !== '') {
            $query = $query->where('title', 'LIKE', '%'.$validated['title'].'%');
        }

        return Inertia::render('tasks/search', ['tasks' => $query->orderBy('id', 'asc')->infinite(2)]);
    }

    public function index(Request $request, string $title): Response
    {
        $query = Task::zeroQuery()->visibleTo($request->user());
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

    public function filtered(Request $request, string $title, bool $showDone, int $window): Response
    {
        $query = Task::zeroQuery()->visibleTo($request->user());
        if (! $showDone) {
            $query = $query->where('done', false);
        }

        if ($title !== '') {
            $query = $query->where('title', 'LIKE', '%'.$title.'%');
        }

        return Inertia::render('tasks/filtered', ['tasks' => $query->orderBy('id', 'asc')->limit($window)]);
    }

    public function relationships(Request $request, string $title): Response
    {
        return Inertia::render('tasks/relationships', [
            'tasks' => Task::zeroQuery()
                ->visibleTo($request->user())
                ->where(fn ($args) => $args->not($args->or(
                    $args->cmp('title', 'IN', [$title, 'hidden']),
                    $args->exists('comments', fn ($query) => $query->visibleTo($request->user())->where('body', 'secret'), ['flip' => true]),
                )))
                ->whereExists('list', fn ($query) => $query->visibleTo($request->user())->where('id', 'list-a'), ['scalar' => true])
                ->whereExists('comments', fn ($query) => $query->visibleTo($request->user()), ['flip' => false])
                ->related('comments', fn ($query) => $query->visibleTo($request->user())->where('body', '!=', $title)->orderBy('id', 'desc')->limit(2))
                ->related('list', fn ($query) => $query->visibleTo($request->user()))
                ->orderBy('title', 'desc')->orderBy('id', 'asc')
                ->start(['title' => $title, 'id' => 'task-a'], ['inclusive' => false]),
        ]);
    }

    public function unsafeSort(string $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->orderBy('id', $direction)]);
    }

    public function sorted(Request $request, QueryDirection $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->orderBy('title', $direction->value)]);
    }

    public function unsafeNullable(QueryRequest $input): Response
    {
        $validated = $input->validated();
        $query = Task::zeroQuery()->visibleTo($input->user());
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
