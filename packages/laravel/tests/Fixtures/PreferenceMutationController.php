<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use ZeroForLaravel\Attributes\ZeroMutation;
use ZeroForLaravel\Mutation\Mutation;

final class PreferenceMutationController
{
    /** @return array{id: string} */
    #[ZeroMutation]
    public function assign(AssignmentRequest $request, Task $task, Mutation $mutation, AssignmentSubscriptions $subscriptions): array
    {
        $task = $mutation->reloadForUpdate($task);
        $change = $request->validated();
        $previous = $task->list_id;
        $changed = array_key_exists('list_id', $change) && $change['list_id'] !== $previous;
        $mutation->update($task, $change);
        if (array_key_exists('list_id', $change) && $task->list_id !== null) {
            $subscriptions->subscribe($mutation, $task, $task->list_id);
        }

        if ($changed && $previous !== null) {
            $subscriptions->unsubscribe($mutation, $task, $previous);
        }

        return ['id' => $task->id];
    }

    #[ZeroMutation]
    public function subscribe(Mutation $mutation): bool
    {
        $mutation->insertUnlessExists(MutationPreference::class, unique: ['recipient' => 'alice', 'issue' => 'issue'], data: ['subscribed' => true], key: 'preference');
        $preference = $mutation->findUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue']);
        if ($preference === null) {
            return false;
        }

        return $preference->subscribed;
    }

    #[ZeroMutation]
    public function unsubscribe(Mutation $mutation): bool
    {
        $mutation->upsertUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue'], ['subscribed' => false], 'unsubscribe');
        $preference = $mutation->findUnique(MutationPreference::class, ['recipient' => 'alice', 'issue' => 'issue']);
        if ($preference === null) {
            return true;
        }

        return $preference->subscribed;
    }
}
