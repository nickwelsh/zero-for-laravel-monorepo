<?php

declare(strict_types=1);

namespace ZeroForLaravel\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Preserve encrypted cookies for controller replay before the web middleware runs. */
final class CaptureTransportCookies
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('zero.transport.cookies', $request->cookies->all());

        return $next($request);
    }
}
