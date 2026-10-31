<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;

class TaskList extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $table = 'lists';

    protected $keyType = 'string';

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorize('user_id', '=', ContextField::UserId)->relations('tasks');
    }

    /**
     * @return HasMany<Task, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'list_id');
    }
}
