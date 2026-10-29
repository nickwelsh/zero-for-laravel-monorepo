<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Closure;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use ZeroForLaravel\ZeroContext;

/** Optional Scout/Typesense metadata. It never exports credentials or authorization closures. */
final class ScoutSearch
{
    /** @var list<string>|null */
    private ?array $facets = null;

    /** @var list<string> */
    private array $sorts = [];

    /** @var list<string> */
    private array $filters = [];

    /** @var (Closure(ZeroContext): string)|null */
    private ?Closure $scope = null;

    /** @param class-string<Model> $model */
    private function __construct(public readonly string $model, public readonly string $key) {}

    /** @param class-string<Model> $model */
    public static function for(string $model, string $key = 'id'): self
    {
        if (! is_subclass_of($model, Model::class) || ! method_exists($model, 'searchableAs')) {
            throw new LogicException('Search requires an explicitly opted-in Scout model.');
        }

        return new self($model, $key);
    }

    public function filters(string ...$fields): self
    {
        $this->filters = array_values($fields);

        return $this;
    }

    public function facets(string ...$fields): self
    {
        $this->facets = array_values($fields);

        return $this;
    }

    public function sorts(string ...$sorts): self
    {
        $this->sorts = array_values($sorts);

        return $this;
    }

    /** Required only for the authenticated transport. Scoped browser keys remain an alternative. */
    /** @param Closure(ZeroContext): string $scope */
    public function scope(Closure $scope): self
    {
        $this->scope = $scope;

        return $this;
    }

    public function filter(ZeroContext $context): string
    {
        if (! $this->scope instanceof Closure) {
            throw new LogicException('The search transport requires an explicit metadata authorization scope.');
        }

        $filter = ($this->scope)($context);
        if ($filter === '') {
            throw new LogicException('The search scope must return a nonempty Typesense filter.');
        }

        return $filter;
    }

    /** @return array{index: string, key: string, queryBy: string, fields: array<string, string>, facets: list<string>, sorts: list<string>} */
    public function metadata(): array
    {
        $model = new $this->model;
        $settings = config('scout.typesense.model-settings.'.$this->model, []);
        if (! is_array($settings)) {
            throw new LogicException('Scout settings must be an array.');
        }

        $schema = method_exists($model, 'typesenseCollectionSchema') ? $model->typesenseCollectionSchema() : ($settings['collection-schema'] ?? []);
        if (! is_array($schema) || ! is_array($schema['fields'] ?? null)) {
            throw new LogicException('Scout requires an explicit collection schema.');
        }

        $fields = [];
        foreach ($schema['fields'] ?? [] as $field) {
            if (! is_array($field) || ! is_string($field['type'] ?? null) || ! is_string($field['name'] ?? null) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $field['name'])) {
                continue;
            }

            $fields[$field['name']] = $field;
        }

        if (! isset($fields[$this->key])) {
            throw new LogicException('The Scout hydration key must be present in the collection schema.');
        }

        $parameters = $settings['search-parameters'] ?? [];
        if (! is_array($parameters)) {
            throw new LogicException('Scout search parameters must be an array.');
        }

        $queryBy = $parameters['query_by'] ?? '';
        if (! is_string($queryBy) || $queryBy === '') {
            throw new LogicException('Scout search-parameters.query_by must be declared.');
        }

        $searchFields = explode(',', $queryBy);
        foreach ($searchFields as $field) {
            if (! isset($fields[$field]) || ! in_array($fields[$field]['type'], ['string', 'string[]'], true)) {
                throw new LogicException('Search fields must be declared string columns.');
            }
        }

        $facets = $this->facets ?? array_keys(array_filter($fields, fn (array $field): bool => ($field['facet'] ?? false) === true));
        foreach ($facets as $field) {
            if (! ($fields[$field]['facet'] ?? false)) {
                throw new LogicException('Search facets must be enabled in the Scout schema.');
            }
        }

        foreach ($this->sorts as $sort) {
            foreach (explode(',', $sort) as $part) {
                [$field, $direction] = array_pad(explode(':', $part), 2, '');
                if (! isset($fields[$field]) || ! in_array($direction, ['asc', 'desc'], true) || ! ($fields[$field]['sort'] ?? in_array($fields[$field]['type'], ['int32', 'int64', 'float', 'bool'], true))) {
                    throw new LogicException('Search sorting requires sortable schema fields.');
                }
            }
        }

        foreach ($this->filters as $field) {
            if (! isset($fields[$field]) || ($fields[$field]['index'] ?? true) !== true) {
                throw new LogicException('Search filters must be indexed schema fields.');
            }
        }

        $public = array_unique([...$searchFields, ...$facets, ...$this->filters]);
        if (! method_exists($model, 'searchableAs')) {
            throw new LogicException('Search requires Scout.');
        }

        $index = $model->searchableAs();
        if (! is_string($index)) {
            throw new LogicException('Scout index names must be strings.');
        }

        return ['index' => $index, 'key' => $this->key, 'queryBy' => $queryBy,
            'fields' => array_column(array_intersect_key($fields, array_flip($public)), 'type', 'name'),
            'facets' => $facets, 'sorts' => $this->sorts];
    }
}
