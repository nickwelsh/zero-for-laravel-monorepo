<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;

class Comment extends Model
{
    use HasPolicyVisibility;
    use HasZeroQueries;

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @return BelongsTo<TaskList, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'event_id');
    }
}
