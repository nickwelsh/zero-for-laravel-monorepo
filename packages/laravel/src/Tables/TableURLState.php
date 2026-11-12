<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Validation\ValidationException;
use JsonException;
use stdClass;
use ZeroForLaravel\Support\Input;

/** Versioned URL inputs only; authorization and mandatory scopes remain on Table. */
final class TableURLState
{
    /** Read the raw query string: PHP's query parser changes dots in parameter names.
     * @param  array<string, mixed>  $defaults  Table then saved-view defaults, shallow merged by the caller.
     * @return array<string, mixed>
     */
    public static function read(Table $table, string $query, string $key, array $defaults = []): array
    {
        $fallback = self::normalize($table, $defaults);
        $values = [];
        foreach (explode('&', ltrim($query, '?')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $name = urldecode($name);
            if (str_starts_with($name, $key.'[')) {
                return $fallback;
            }

            if ($name === $key) {
                $values[] = urldecode($value);
            }
        }

        if (count($values) !== 1 || strlen($values[0]) > 16384) {
            return $fallback;
        }

        try {
            $envelope = json_decode($values[0], flags: JSON_THROW_ON_ERROR);
            if (! $envelope instanceof stdClass || ! ($envelope->state ?? null) instanceof stdClass) {
                return $fallback;
            }

            $envelope = Input::object($envelope, 'url');
            Input::keys($envelope, ['version', 'state'], 'url');
            if (! in_array($envelope['version'] ?? null, [1, 1.0], true)) {
                return $fallback;
            }

            return self::normalize($table, [...$fallback, ...Input::object($envelope['state'], 'state')]);
        } catch (JsonException|ValidationException) {
            return $fallback;
        }
    }

    /** @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function normalize(Table $table, array $overrides = []): array
    {
        Input::keys($overrides, ['filter', 'sort', 'groupBy', 'expanded'], 'state');
        $state = [...['filter' => null, 'sort' => $table->defaultOrdering, 'groupBy' => $table->defaultGrouping, 'expanded' => []], ...$overrides];
        (new Predicate($table->base, $table->filters))->parse($state['filter']);
        $state['sort'] = $table->ordering($state['sort']);
        $state['groupBy'] = $table->grouping($state['groupBy']);
        $expanded = $state['expanded'];
        if (! is_array($expanded) || ! array_is_list($expanded) || count($expanded) > 100) {
            Input::fail('expanded', 'Expected at most 100 group IDs.');
        }

        foreach ($expanded as $id) {
            if (! is_string($id) || strlen($id) > 2048) {
                Input::fail('expanded', 'Expected group IDs of at most 2048 bytes.');
            }
        }

        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function encode(Table $table, array $state): string
    {
        $encoded = json_encode(['version' => 1, 'state' => self::normalize($table, $state)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
        if (strlen($encoded) > 16384) {
            Input::fail('state', 'URL state exceeds 16384 bytes.');
        }

        return $encoded;
    }
}
