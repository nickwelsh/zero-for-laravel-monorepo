<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Support\Facades\Gate;
use ZeroForLaravel\Attributes\ZeroShared;
use ZeroForLaravel\Mutation\Mutation;

#[ZeroShared]
final class AssignmentSubscriptions
{
    public function subscribe(Mutation $mutation, Task $task, string $recipient): void
    {
        Gate::authorize('recipient-visibility', [$task, $recipient]);
        $mutation->insertUnlessExists(MutationPreference::class, ['recipient' => $recipient, 'issue' => $task->id], ['subscribed' => true], 'subscribe');
    }

    public function unsubscribe(Mutation $mutation, Task $task, string $recipient): void
    {
        Gate::authorize('recipient-visibility', [$task, $recipient]);
        $mutation->upsertUnique(MutationPreference::class, ['recipient' => $recipient, 'issue' => $task->id], ['subscribed' => false], 'unsubscribe');
    }
}
