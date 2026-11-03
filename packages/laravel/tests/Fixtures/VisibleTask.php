<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

class VisibleTask extends Model
{
    public $timestamps = false;

    protected $table = 'tasks';

    protected $visible = ['id', 'title'];

    protected $fillable = ['title', 'secret'];
}
