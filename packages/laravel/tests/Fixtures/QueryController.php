<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Zero;

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
        return Inertia::render('tasks/window', ['tasks' => Zero::scroll(Task::zeroQuery()->visibleTo($request->user())->orderBy('title', 'desc')->paginate(2))]);
    }

    public function pages(Request $request): Response
    {
        return Inertia::render('tasks/pages', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->orderBy('created_at', 'desc')->cursorPaginate(2)]);
    }

    public function dated(DateQueryRequest $input): Response
    {
        $validated = $input->validated();

        return Inertia::render('tasks/dated', ['tasks' => Task::zeroQuery()->visibleTo($input->user())->where('completed_at', '>=', $validated['after'])->get()]);
    }

    public function choices(ChoiceQueryRequest $input): Response
    {
        return Inertia::render('tasks/choices', ['tasks' => Zero::scroll(Task::zeroQuery()->visibleTo($input->user())->orderBy('id', 'asc')->paginate(2))]);
    }

    public function search(QueryRequest $input): Response
    {
        $validated = $input->validated();
        $query = Task::zeroQuery()->visibleTo($input->user());
        if (isset($validated['title']) && $validated['title'] !== '') {
            $query = $query->where('title', 'LIKE', '%'.$validated['title'].'%');
        }

        return Inertia::render('tasks/search', ['tasks' => Zero::scroll($query->orderBy('id', 'asc')->paginate(2))]);
    }

    public function index(Request $request, string $title): Response
    {
        $query = Task::zeroQuery()->visibleTo($request->user());
        if ($title !== '') {
            $query = $query->where('title', $title);
        }

        return Inertia::render('tasks/index', [
            'heading' => 'Tasks',
            'tasks' => $query->orderBy('id', 'asc')->get(),
        ]);
    }

    public function unsafe(): Response
    {
        abort_unless(auth()->check(), 403);

        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()]);
    }

    public function filtered(Request $request, string $title, bool $showDone, int $window): Response
    {
        $query = Task::zeroQuery()->visibleTo($request->user())
            ->unless($showDone, fn ($query) => $query->where(['done' => false]))
            ->when($title !== '', fn ($query) => $query->where('title', 'like', '%'.$title.'%'));

        return Inertia::render('tasks/filtered', ['tasks' => $query->orderBy('id')->take($window)->get()]);
    }

    public function relationships(Request $request, string $title): Response
    {
        return Inertia::render('tasks/relationships', [
            'tasks' => Task::zeroQuery()
                ->visibleTo($request->user())
                ->whereNotIn('title', [$title, 'hidden'])
                ->whereDoesntHave('comments', fn ($query) => $query->visibleTo($request->user())->where('body', 'secret'))
                ->whereHas('list', fn ($query) => $query->visibleTo($request->user())->whereKey('list-a'))
                ->whereHas('comments', fn ($query) => $query->visibleTo($request->user()))
                ->with('comments', fn ($query) => $query->visibleTo($request->user())->where('body', '!=', $title)->orderBy('id', 'desc')->limit(2))
                ->with('list', fn ($query) => $query->visibleTo($request->user()))
                ->orderBy('title', 'desc')->orderBy('id', 'asc')
                ->get(),
        ]);
    }

    public function unsafeSort(string $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->orderBy('id', $direction)]);
    }

    public function sorted(Request $request, QueryDirection $direction): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->orderBy('title', $direction->value)->get()]);
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
