<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use LogicException;
use ZeroForLaravel\Attributes\ResolvesContext;

final class ContextResolver
{
    #[ResolvesContext(ZeroContext::USER_ID)]
    public function userId(Request $request): ?string
    {
        $id = $request->user()?->getAuthIdentifier();
        if ($id !== null && ! is_scalar($id)) {
            throw new LogicException('Authenticated identifiers must be scalar.');
        }

        return $id === null ? null : (string) $id;
    }
}
