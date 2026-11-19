<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\Revocation;

final class DynamicRevocationPolicy
{
    public function view(Authenticatable $user, Model $resource): Response
    {
        return Casbin::inspect($user, 'view', $resource)->onRevoked(
            Revocation::redirectToRoute('tasks.shell')->message($resource->getTable())
        );
    }
}
