<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

final class CustomAuthorizationArgumentsController extends LaravelAuthorizationController
{
    /** @return array{string, mixed} */
    protected function parseAbilityAndArguments($ability, $arguments): array
    {
        return ['different-ability', $arguments];
    }
}
