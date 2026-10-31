<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class WayfinderSharedMiddleware extends Middleware
{
    /** @return array{sharedMarker: string} */
    public function share(Request $request): array
    {
        return ['sharedMarker' => 'Preserved'];
    }
}
