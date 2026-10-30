<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\ContextField;
use ZeroForLaravel\Definition\ZeroDefinition;

/**
 * @property string $id
 * @property int $user_id
 * @property string $name
 */
class TodoList extends Model
{
    use HasUuids;
    use HasZeroQueries;

    protected $fillable = ['name', 'user_id'];

    public static function zero(): ZeroDefinition
    {
        return ZeroDefinition::make()->authorizeRelation('memberships')->fillFromContext('user_id', ContextField::UserId)
            ->allowedIncludes(['todos']);
    }

    /** @return HasMany<ListMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(ListMembership::class, 'list_id');
    }

    /** @return HasMany<Todo, $this> */
    public function todos(): HasMany
    {
        return $this->hasMany(Todo::class, 'list_id');
    }

    public function accessibleBy(User $user): bool
    {
        return $this->memberships()->where('user_id', $user->id)->exists();
    }

    protected static function booted(): void
    {
        static::created(function (self $list): void {
            $list->memberships()->create(['user_id' => $list->user_id]);
        });
    }
}
