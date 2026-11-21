<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use LogicException;

/** Shared resource boundaries for authenticated query transforms and live windows. */
final class QueryLimits
{
    public const int NATIVE_MAX = 2147483647;

    public const array POSITION_KEYS = ['__page', '__window', '__cursor', '__reverse'];

    public static function validPageName(string $name): bool
    {
        return preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $name) === 1;
    }

    public static function transformBatch(): int
    {
        return self::configured('zero.limits.max_transform_queries', 256);
    }

    public static function transformBudget(): int
    {
        $budget = self::configured('zero.limits.max_transform_queries_per_minute', 4096);
        if ($budget < self::transformBatch()) {
            throw new LogicException('zero.limits.max_transform_queries_per_minute must admit one complete transform batch.');
        }

        return $budget;
    }

    public static function liveWindow(): int
    {
        return self::configured('zero.limits.max_live_window', 10000, 2);
    }

    private static function configured(string $key, int $default, int $minimum = 1): int
    {
        $value = config($key, $default);
        if (! is_int($value) || $value < $minimum || $value > self::NATIVE_MAX) {
            throw new LogicException("{$key} must be an integer between {$minimum} and ".self::NATIVE_MAX.'.');
        }

        return $value;
    }
}
