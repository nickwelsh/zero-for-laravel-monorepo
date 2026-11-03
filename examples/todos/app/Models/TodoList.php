<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ZeroContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Query\ZeroQuery;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 */
class TodoList extends Model
{
    use HasPolicyVisibility;
    use HasUuids;
    use HasZeroQueries;

    protected $fillable = ['name', 'user_id'];

    protected static function booted(): void
    {
        static::addZeroGlobalScope('creator', function (ZeroQuery $query): void {
            $query->withAttributes(['user_id' => ZeroContext::USER_ID], asConditions: false);
        });
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'list_id');
    }
}
