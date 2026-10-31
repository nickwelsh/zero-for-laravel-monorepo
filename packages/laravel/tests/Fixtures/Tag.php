<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;

class Tag extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorize('user_id', '=', ContextField::UserId);
    }
}
