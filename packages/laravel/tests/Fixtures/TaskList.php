<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;

class TaskList extends Model
{
    use HasPolicyVisibility;
    use HasZeroQueries;

    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'lists';

    protected $keyType = 'string';

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }
}
