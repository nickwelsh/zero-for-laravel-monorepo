<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

/** Opaque markers derived only from documents visible under a server-resolved scope. */
final class SearchRevision
{
    public static function current(string $index, string $filter, string $documents): string
    {
        $revisions = [];
        foreach (preg_split('/\r\n|\n|\r/', trim($documents)) ?: [] as $document) {
            if ($document !== '') {
                $revisions[] = hash('sha256', $document);
            }
        }

        sort($revisions, SORT_STRING);

        $domain = json_encode([$index, $filter], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $domain."\n".implode("\n", $revisions), config()->string('app.key'));
    }
}
