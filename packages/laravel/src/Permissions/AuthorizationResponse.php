<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Auth\Access\Response;

final class AuthorizationResponse extends Response
{
    public function asNotAuthorized(): static
    {
        return $this->withStatus(403);
    }
}
