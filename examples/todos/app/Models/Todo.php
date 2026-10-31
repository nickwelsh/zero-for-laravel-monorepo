<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Definition\ZeroDefinition;

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

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorizeRelation('list')
            ->relations('list');
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
