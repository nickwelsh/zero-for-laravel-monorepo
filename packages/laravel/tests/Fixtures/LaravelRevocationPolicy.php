<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Permissions\Casbin;
use ZeroForLaravel\Permissions\Revocation;

class LaravelRevocationPolicy
{
    public function view(Authenticatable $user, Model $resource): Response
    {
        return Casbin::inspect($user, 'view', $resource)->asNotFound()->onRevoked(
            Revocation::redirectToRoute('tasks.shell')->message('Access was revoked.')
        );
    }
}
