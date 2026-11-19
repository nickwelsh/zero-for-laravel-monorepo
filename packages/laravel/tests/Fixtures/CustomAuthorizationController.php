<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Auth\Access\Response;

/** A controller method with the same name is not Laravel authorization. */
final class CustomAuthorizationController extends LaravelAuthorizationController
{
    public function authorize($ability, $arguments = []): Response
    {
        return Response::allow();
    }
}
