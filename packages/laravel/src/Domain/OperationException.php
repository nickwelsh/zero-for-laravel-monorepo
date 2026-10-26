<?php

declare(strict_types=1);

namespace ZeroForLaravel\Domain;

use RuntimeException;

/** Intentionally public rejection. Unexpected internal errors may be retried. */
final class OperationException extends RuntimeException
{
    public function __construct(public readonly string $operationCode, string $message)
    {
        parent::__construct($message);
    }
}
