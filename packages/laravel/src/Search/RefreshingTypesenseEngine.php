<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Laravel\Scout\Engines\TypesenseEngine;
use LogicException;
use Stringable;
use Typesense\Collection as TypesenseCollection;
use Typesense\Exceptions\ObjectNotFound;

/** Opt in through Scout's engine manager; covers queued and synchronous indexing. */
final class RefreshingTypesenseEngine extends TypesenseEngine
{
    /** @param \Illuminate\Database\Eloquent\Collection<int, Model> $models */
    public function update($models): void
    {
        parent::update($models);
        $this->changed($models);
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, Model> $models */
    public function delete($models): void
    {
        parent::delete($models);
        $this->changed($models);
    }

    /** @param Model $model */
    public function flush($model): void
    {
        parent::flush($model);
        $this->changed([$model]);
    }

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

    /** @param iterable<Model> $models */
    private function changed(iterable $models): void
    {
        $indexes = [];
        foreach ($models as $model) {
            if (! method_exists($model, 'searchableAs')) {
                throw new LogicException('Search indexing requires a Scout model.');
            }

            $index = $model->searchableAs();
            if (! is_string($index)) {
                throw new LogicException('Scout index names must be strings.');
            }

            $indexes[$index] = true;
        }

        foreach (array_keys($indexes) as $index) {
            SearchRevision::touch($index);
        }
    }
}
