<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\ResolvedContext;

/** Definitions are compiled symbolically; runtime context comes only from Laravel. */
final readonly class TableContext
{
    public function __construct(private Request $request) {}

    public function user(): ?Authenticatable
    {
        return $this->request->user();
    }

    public function resolved(): ResolvedContext
    {
        return ContextDefinition::configured()->resolve($this->request);
    }
}
