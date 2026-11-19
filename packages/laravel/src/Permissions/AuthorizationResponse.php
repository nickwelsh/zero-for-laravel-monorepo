<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Auth\Access\Response;

final class AuthorizationResponse extends Response
{
    private ?Revocation $revocation = null;

    public function asNotAuthorized(): static
    {
        return $this->withStatus(403);
    }

    public function onRevoked(Revocation $revocation): static
    {
        $this->revocation = $revocation;

        return $this;
    }

    public function revocation(): ?Revocation
    {
        return $this->revocation;
    }
}
