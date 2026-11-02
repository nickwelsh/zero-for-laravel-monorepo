<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

final readonly class HigherOrderQuery
{
    public function __construct(private ZeroQuery $query) {}

    /** @param array<array-key, mixed> $arguments */
    public function __call(string $scope, array $arguments): ZeroQuery
    {
        return $this->query->orWhere(fn (ZeroQuery $query): ZeroQuery => $query->__call($scope, $arguments));
    }
}
