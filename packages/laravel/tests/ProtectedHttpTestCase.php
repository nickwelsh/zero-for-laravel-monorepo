<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use ZeroForLaravel\ZeroServiceProvider;

abstract class ProtectedHttpTestCase extends OwnerPostgresTestCase
{
    protected function getPackageProviders($app): array
    {
        return [ZeroServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('zero.api_key', 'private-test-secret');
        $app['config']->set('zero.middleware', []);
    }
}
