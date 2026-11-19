<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

class LaravelAuthorizationController
{
    use AuthorizesRequests;

    public function show(Task $task): Response
    {
        $this->authorize('view', $task);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function guardedCollection(Task $task, Request $request): Response
    {
        $this->authorize('view', $task);

        return Inertia::render('tasks/show', ['task' => $task, 'tasks' => Task::zeroQuery()->visibleTo($request->user())]);
    }

    public function literalText(Request $request): Response
    {
        return Inertia::render('tasks/index', ['tasks' => Task::zeroQuery()->visibleTo($request->user())->where('title', 'args[ ctx[ encodeDate(')]);
    }

    public function shell(): Response
    {
        return Inertia::render('tasks/shell');
    }

    public function emptyShell(): Response
    {
        return Inertia::render('tasks/shell', []);
    }

    public function boundShell(Task $task): Response
    {
        $this->authorize('view', $task);

        return Inertia::render('tasks/shell');
    }

    public function ordinaryShell(): Response
    {
        return Inertia::render('tasks/shell', ['heading' => 'Required ordinary prop']);
    }

    public function classShell(Task $task): Response
    {
        $this->authorize('create', Task::class);

        return Inertia::render('tasks/shell', []);
    }

    public function dynamic(Task $task, string $ability): Response
    {
        $this->authorize($ability, $task);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function named(Task $task): Response
    {
        $this->authorize(ability: 'view', arguments: $task);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    public function inferred(Task $task): Response
    {
        $this->authorize($task);

        return Inertia::render('tasks/show', ['task' => $task]);
    }

    /** @return array{done: bool, title: string} */
    #[ZeroMutation]
    public function store(ControllerMutationRequest $request): array
    {
        $this->authorize('controller-create', Task::class);
        $data = $request->validated();
        $task = Task::create(['title' => $data['title'], 'done' => $data['done'] ?? false]);

        return ['done' => $task->done, 'title' => $task->title];
    }

    #[ZeroMutation]
    public function update(ControllerMutationRequest $request, Task $task): void
    {
        $this->authorize('controller-update', $task);
        $task->update($request->validated());
    }

    #[ZeroMutation]
    public function dynamicMutation(ControllerMutationRequest $request, Task $task, string $ability): void
    {
        $this->authorize($ability, $task);
    }

    #[ZeroMutation]
    public function namedMutation(ControllerMutationRequest $request, Task $task): void
    {
        $this->authorize(ability: 'controller-update', arguments: $task);
    }

    #[ZeroMutation]
    public function requiredPrerequisite(Mutation $mutation): void
    {
        $prerequisite = $mutation->findUnique(TaskList::class, ['id' => '00000000-0000-4000-8000-000000000001']);
        // @phpstan-ignore property.nonObject (Intentional compiler input: ordinary null-model reads must reject.)
        if ($prerequisite->name !== 'blocked') {
            Task::create(['title' => 'Created with prerequisite']);
        }
    }

    /** @return array{label: string, present: bool} */
    #[ZeroMutation]
    public function optionalPrerequisite(Mutation $mutation): array
    {
        $prerequisite = $mutation->findUnique(TaskList::class, ['id' => '00000000-0000-4000-8000-000000000001']);

        return ['label' => $prerequisite->name ?? 'Default label', 'present' => isset($prerequisite->name)];
    }

    /** @return array<string, mixed> */
    #[ZeroMutation]
    public function defaults(CoalesceMutationRequest $request): array
    {
        $data = $request->validated();

        return [
            'note' => $data['note'] ?? 'Default',
            'flag' => $data['flag'] ?? true,
            'count' => $data['count'] ?? 7,
            'label' => $data['settings']['label'] ?? 'Default label',
        ];
    }
}
