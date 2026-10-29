<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Opaque index-level markers, updated only after the search engine succeeds. */
final class SearchRevision
{
    public static function current(string $index): string
    {
        $revision = Cache::get(self::key($index), '');

        return is_string($revision) ? $revision : '';
    }

    public static function touch(string $index): void
    {
        Cache::forever(self::key($index), (string) Str::uuid());
    }

    private static function key(string $index): string
    {
        return 'zero:search:revision:'.hash('sha256', config()->string('zero.app_id').':'.$index);
    }
}
