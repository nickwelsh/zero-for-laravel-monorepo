<?php

declare(strict_types=1);

namespace ZeroForLaravel\Context;

use Illuminate\Http\Request;
use ZeroForLaravel\Attributes\ResolvesContext;
use ZeroForLaravel\ResolvedContext;

final class ResolveZeroContext
{
    #[ResolvesContext(ZeroContext::USER_ID)]
    public function userId(Request $request): ?string
    {
        return ResolvedContext::authenticatedId($request);
    }
}
