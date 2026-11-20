<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures\Todos;

use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasPolicyVisibility;
use ZeroForLaravel\Concerns\HasZeroQueries;
use ZeroForLaravel\Permissions\VisibleToCurrentUser;

/** @property string $id */
#[ScopedBy([VisibleToCurrentUser::class])]
final class Todo extends Model
{
    use HasPolicyVisibility, HasUuids, HasZeroQueries;

    protected $fillable = ['title'];
}
