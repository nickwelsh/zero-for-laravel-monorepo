<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests;

use ZeroForLaravel\Tests\Fixtures\OwnerPermissions;

/** Owner fixtures opt into their own bindings without changing permission-suite defaults. */
abstract class OwnerTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        OwnerPermissions::configure();
    }
}
