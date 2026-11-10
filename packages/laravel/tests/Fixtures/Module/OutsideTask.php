<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Module;

use ZeroForLaravel\Attributes\ZeroPublic;
use ZeroForLaravel\Tests\Fixtures\VisibleTask;

#[ZeroPublic]
class OutsideTask extends VisibleTask {}
