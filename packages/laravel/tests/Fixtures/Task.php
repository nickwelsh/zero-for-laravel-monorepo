<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Query\ZeroQuery;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $list_id
 * @property string $title
 * @property bool $done
 * @property CarbonImmutable|null $completed_at
 * @property Carbon $created_at
 */
class Task extends Model
{
    use HasPolicyVisibility;
    use HasUuids, SoftDeletes;
    use HasZeroQueries;

    protected $table = 'tasks';

    protected $fillable = ['user_id', 'list_id', 'title', 'done', 'completed_at'];

    protected $hidden = ['secret'];

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

    protected static function booted(): void
    {
        static::addZeroGlobalScope('creator', function (ZeroQuery $query): void {
            $query->withAttributes(['user_id' => ZeroContext::USER_ID], asConditions: false);
        });
    }

    protected function casts(): array
    {
        return ['done' => 'boolean', 'completed_at' => 'immutable_datetime'];
    }
}
