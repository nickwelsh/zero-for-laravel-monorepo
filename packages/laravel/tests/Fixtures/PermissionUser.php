<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/** @property bool $is_admin */
class PermissionUser extends User
{
    public $timestamps = false;

    protected $table = 'permission_test_users';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_admin' => 'boolean'];
    }
}
