<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Concerns\HasZeroQueries;

#[ZeroScopedBy([OwnerReadScope::class])]
class Tag extends Model
{
    use HasZeroQueries;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';
}
