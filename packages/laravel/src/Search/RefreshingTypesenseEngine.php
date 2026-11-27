<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use InvalidArgumentException;
use Laravel\Scout\Engines\TypesenseEngine;
use Stringable;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\ObjectNotFound;

/** Compatible Scout engine with idempotent deletion for already-removed documents. */
final class RefreshingTypesenseEngine extends TypesenseEngine
{
    /** @return array<array-key, mixed> */
    protected function deleteDocument(TypesenseCollection $collectionIndex, $modelId): array
    {
        if (! is_scalar($modelId) && ! $modelId instanceof Stringable) {
            throw new InvalidArgumentException('Search document IDs must be scalar or stringable.');
        }

        try {
            return $collectionIndex->getDocuments()[(string) $modelId]->delete();
        } catch (ObjectNotFound) {
            return [];
        }
    }
}
