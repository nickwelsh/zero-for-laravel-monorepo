<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;

#[UsePolicy(PermissionListPolicy::class)]
class PermissionList extends Model
{
    use HasPolicyVisibility;
    use HasZeroQueries;

    public $timestamps = false;

    protected $table = 'permission_test_lists';

    protected $guarded = [];
}
