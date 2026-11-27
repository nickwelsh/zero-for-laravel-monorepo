<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\Concerns\HasZeroQueries;

final class PredicateTask extends Model
{
    use HasZeroQueries;

    protected $table = 'tasks';

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id')->whereIn('body', ['published']);
    }
}
