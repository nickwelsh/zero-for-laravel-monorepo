<?php

declare(strict_types=1);

namespace App\Zero;

use App\Enums\ZeroContext;
use Illuminate\Http\Request;
use ZeroForLaravel\Attributes\ResolvesContext;

final class ResolveZeroContext
{
    #[ResolvesContext(ZeroContext::USER_ID)]
    public function userId(Request $request): int
    {
        abort_unless($request->user() !== null, 403);

        return (int) $request->user()->getAuthIdentifier();
    }
}
