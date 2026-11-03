<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

final class OwnerUser extends User
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'users';

    protected $keyType = 'string';
}
