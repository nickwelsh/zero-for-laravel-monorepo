<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Definition\ZeroDefinition;

class VisibleTask extends Model
{
    public $timestamps = false;

    protected $table = 'tasks';

    protected $visible = ['id', 'title'];

    protected $fillable = ['title', 'secret'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->publicReads();
    }
}
