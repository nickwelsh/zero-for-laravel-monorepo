<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use ZeroForLaravel\Attributes\ZeroShared;
use ZeroForLaravel\Mutation\Mutation;

#[ZeroShared]
final class ControllerMutationHelper
{
    public function complete(Mutation $mutation, Task $task): void
    {
        $mutation->update($task, ['done' => true]);
    }
}
