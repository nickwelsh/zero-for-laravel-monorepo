<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Native table reads share the existing authenticated transform and visibility compiler. */
final readonly class RegisteredTableQuery
{
    public function __construct(public string $table, public string $contract) {}

    /** @return array<string, mixed> */
    public function invoke(mixed $arguments, ResolvedContext $identity, QueryCompiler $compiler): array
    {
        $args = Input::object($arguments, 'args');
        if (isset($args['__generation']) && (! is_string($args['__generation']) || ! Str::isUuid($args['__generation']))) {
            Input::fail('generation', 'Query generations must be UUIDs.');
        }

        $context = ContextDefinition::configured()->resolve(request());
        if ($context->userId() === null || $context->userId() !== $identity->userId()) {
            throw new AuthorizationException('Table identity differs from the authenticated Zero identity.');
        }

        $definition = resolve(TableRegistry::class)->find($this->table, $this->contract);
        $definition->bind(request()->user());

        return $compiler->compileDeclaration($definition->rows($args), $context, serving: true);
    }
}
