<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Auth\Access\Response;
use ZeroForLaravel\Permissions\Casbin;

class PermissionListPolicy
{
    public function view(PermissionUser $user, PermissionList $list): Response
    {
        return Casbin::inspect($user, 'view', $list);
    }

    public function update(PermissionUser $user, PermissionList $list): Response
    {
        return Casbin::inspect($user, 'update', $list)->asNotFound();
    }

    public function delete(PermissionUser $user, PermissionList $list): Response
    {
        return Casbin::inspect($user, 'delete', $list)->asNotAuthorized();
    }
}
