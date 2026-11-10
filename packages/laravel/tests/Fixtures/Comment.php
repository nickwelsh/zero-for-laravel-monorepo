<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;

class Comment extends Model
{
    use HasPolicyVisibility;
    use HasZeroQueries;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
