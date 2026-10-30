<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

final class ControllerMutationController
{
    /** @return array{id:string} */
    #[ZeroMutation]
    public function store(ControllerMutationRequest $request): array
    {
        $data = $request->validated();
        $task = Task::create($data);
        if (isset($data['done']) && $data['done'] === true) {
            Task::create(['title' => $task->id]);
        }

        return ['id' => $task->id];
    }

    /** @return array{before:bool,after:bool} */
    #[ZeroMutation]
    public function complete(ControllerMutationRequest $request, Task $task, Mutation $mutation, ControllerMutationHelper $helper): array
    {
        $task = $mutation->reloadForUpdate($task);
        Gate::authorize('controller-complete', $task);
        $before = $task->done;
        $helper->complete($mutation, $task);

        return ['before' => $before, 'after' => $task->done];
    }

    #[ZeroMutation(optimism: 'disabled')]
    public function authoritative(ControllerMutationRequest $request): null
    {
        return null;
    }

    /** @return array<string, mixed> */
    #[ZeroMutation(optimism: 'disabled', serverResult: 'array{items: list<string>, meta: array{note?: string}}')]
    public function structured(ControllerMutationRequest $request): array
    {
        $data = $request->validated();
        Task::create(['title' => $data['title'], 'user_id' => 'alice']);

        return ['items' => $data['title'] === 'invalid' ? [42] : ['saved'], 'meta' => []];
    }

    /** @return array{id: string} */
    #[ZeroMutation(optimism: 'custom', serverResult: 'array{id: string}', clientResult: 'array{pending: bool}|void')]
    public function projected(ControllerMutationRequest $request): array
    {
        return ['id' => 'server-assigned'];
    }

    #[ZeroMutation]
    public function unsupported(ControllerMutationRequest $request): void
    {
        file_put_contents('/tmp/unsupported-zero-effect', 'not allowed');
    }

    /** @return array{result:string} */
    #[ZeroMutation]
    public function dependent(ControllerMutationRequest $request): array
    {
        $result = Mutation::server(fn (): string => 'unknown');

        return ['result' => $result];
    }

    #[ZeroMutation]
    public function noResult(ControllerMutationRequest $request): void {}

    #[ZeroMutation]
    public function bulk(BulkMutationRequest $request): void
    {
        $data = $request->validated();
        foreach ($data['items'] as $item) {
            Task::create(['title' => $item['title'], 'completed_at' => $item['at']]);
        }
    }

    /** @return array{id:string} */
    #[ZeroMutation(conceal: true)]
    public function concealed(ControllerMutationRequest $request, Task $task): array
    {
        Gate::authorize('controller-complete', $task);

        return ['id' => $task->id];
    }

    /** @return array{id:string} */
    #[ZeroMutation]
    public function explicit(ControllerMutationRequest $request, Mutation $mutation): array
    {
        $task = $mutation->create(Task::class, data: $request->validated(), key: 'task');

        return ['id' => $task->id];
    }

    /** @return array{title:string} */
    #[ZeroMutation]
    public function tainted(ControllerMutationRequest $request, Task $task): array
    {
        Mutation::server(function () use ($task): void {
            $task->title = 'server';
        });

        return ['title' => $task->title];
    }
}
