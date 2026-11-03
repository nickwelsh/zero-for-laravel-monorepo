<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\SoftDeletes;

class PermissionSoftDeletedList extends PermissionList
{
    use SoftDeletes;
}
