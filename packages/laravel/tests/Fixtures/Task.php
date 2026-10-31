<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $list_id
 * @property string $title
 * @property bool $done
 * @property \Carbon\CarbonImmutable|null $completed_at
 * @property \Illuminate\Support\Carbon $created_at
 */
class Task extends Model
{
    use HasUuids, SoftDeletes;
    use \ZeroForLaravel\Concerns\HasZeroQueries;

    protected $table = 'tasks';

    protected $fillable = ['user_id', 'list_id', 'title', 'done', 'completed_at'];

    protected $hidden = ['secret'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()
            ->authorize('user_id', '=', ContextField::UserId)
            ->fillFromContext('user_id', ContextField::UserId)
            ->relations('list', 'tags', 'comments');
    }

    /**
     * @return BelongsTo<TaskList, $this>
     */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TaskList::class, 'list_id');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'task_tag', 'task_id', 'tag_id');
    }

    /**
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'task_id');
    }

    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }
}
