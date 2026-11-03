<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasPolicyVisibility;

class Comment extends Model
{
    use HasPolicyVisibility;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
