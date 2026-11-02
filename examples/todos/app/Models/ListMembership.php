<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasZeroQueries;

/**
 * @property string $id
 * @property string $list_id
 * @property int $user_id
 */
class ListMembership extends Model
{
    use HasUuids;
    use HasZeroQueries;

    protected $fillable = ['list_id', 'user_id'];
}
