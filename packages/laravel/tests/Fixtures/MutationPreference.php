<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Concerns\HasZeroQueries;

/** @property string $id
 * @property bool $subscribed
 * @property string $note
 */
final class MutationPreference extends Model
{
    use HasUuids, HasZeroQueries;

    protected $table = 'mutation_preferences';

    protected $fillable = ['recipient', 'issue', 'subscribed', 'note'];

    protected function casts(): array
    {
        return ['subscribed' => 'boolean'];
    }
}
