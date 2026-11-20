<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Todos;

use Illuminate\Auth\Access\Response;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Tests\Fixtures\OwnerUser;

final class TodoPolicy
{
    public function viewAny(OwnerUser $user): Response
    {
        return Casbin::inspect($user, 'viewAny', Todo::class)->asNotFound();
    }

    public function view(OwnerUser $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'view', $todo)->asNotFound();
    }

    public function create(OwnerUser $user): Response
    {
        return Casbin::inspect($user, 'create', Todo::class)->asNotFound();
    }

    public function update(OwnerUser $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'update', $todo)->asNotFound();
    }

    public function delete(OwnerUser $user, Todo $todo): Response
    {
        return Casbin::inspect($user, 'delete', $todo)->asNotFound();
    }
}
