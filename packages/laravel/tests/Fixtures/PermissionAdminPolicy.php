<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

class PermissionAdminPolicy extends PermissionListPolicy
{
    public function before(PermissionUser $user, string $ability): ?bool
    {
        return $user->is_admin ? true : null;
    }
}
