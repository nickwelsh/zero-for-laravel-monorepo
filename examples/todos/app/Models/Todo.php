<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Attributes\ZeroScope;
use ZeroForLaravel\Query\ZeroQuery;

/**
 * @property string $id
 * @property string $list_id
 * @property string $title
 * @property bool $completed
 * @property-read TodoList $list
 */
class Todo extends Model
{
    use HasUuids;
    use HasZeroQueries;

    protected $fillable = ['list_id', 'title', 'completed'];

    protected static function booted(): void
    {
        static::addZeroGlobalScope('accessibleList', function (ZeroQuery $query): void {
            $query->whereHas('list');
        });
    }

    #[ZeroScope]
    protected function completed(ZeroQuery $query): void
    {
        $query->where('completed', true);
    }

    #[ZeroScope]
    protected function status(ZeroQuery $query, bool $completed): void
    {
        $query->where('completed', $completed);
    }

    /** @return BelongsTo<TodoList, $this> */
    public function list(): BelongsTo
    {
        return $this->belongsTo(TodoList::class, 'list_id');
    }

    protected function casts(): array
    {
        return ['completed' => 'boolean'];
    }
}
