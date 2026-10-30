<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use ZeroForLaravel\Generation\SchemaDriver;

/** Optional extension; the query-owned base interface stays unchanged. */
interface MutationSchemaDriver extends SchemaDriver
{
    public function value(ValueContract $contract): string;
}
