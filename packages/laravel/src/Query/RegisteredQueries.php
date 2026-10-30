<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use LogicException;

/** Loads only the application's generated PHP artifact, never request data. */
final readonly class RegisteredQueries
{
    /** @param array<string, RegisteredQuery> $queries */
    public function __construct(private array $queries) {}

    public static function load(string $path): self
    {
        if (! is_file($path)) {
            throw new LogicException('Missing controller query registrations. Run zero:generate.');
        }

        $queries = require $path;
        if (! is_array($queries)) {
            throw new LogicException('Invalid controller query registrations. Run zero:generate.');
        }

        $registrations = [];
        foreach ($queries as $name => $query) {
            if (! is_string($name) || ! $query instanceof RegisteredQuery) {
                throw new LogicException('Invalid controller query registration. Run zero:generate.');
            }

            $registrations[$name] = $query;
        }

        return new self($registrations);
    }

    /** @return array<string, RegisteredQuery> */
    public function all(): array
    {
        return $this->queries;
    }

    /** @return array<string, RegisteredQuery> */
    public function forAction(string $action): array
    {
        return array_filter($this->queries, fn (RegisteredQuery $query): bool => $query->action === ltrim($action, '\\'));
    }
}
