<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\Attributes\ZeroScopedBy;
use ZeroForLaravel\Concerns\HasZeroQueries;

#[ZeroScopedBy([OwnerReadScope::class])]
class TaskList extends Model
{
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
