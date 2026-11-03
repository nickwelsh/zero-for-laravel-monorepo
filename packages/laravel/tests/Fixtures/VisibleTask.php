<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasZeroQueries;

class VisibleTask extends Model
{
    use HasZeroQueries;

    public $timestamps = false;

    protected $table = 'tasks';

    protected $visible = ['id', 'title'];

    protected $fillable = ['title', 'secret'];
}
