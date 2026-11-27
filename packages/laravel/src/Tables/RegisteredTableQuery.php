<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use LogicException;
use ZeroForLaravel\Context\ContextDefinition;
use ZeroForLaravel\Formbraid\OptionPattern;
use ZeroForLaravel\Query\QueryCompiler;
use ZeroForLaravel\Query\RegisteredQueries;
use ZeroForLaravel\Query\RegisteredQuery;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Native table reads share the existing authenticated transform and visibility compiler. */
final readonly class RegisteredTableQuery
{
    /** @param list<string> $bindings */
    public function __construct(public string $table, public string $contract, public ?string $operation = null, private string $path = '', private array $bindings = [], private string $queryName = '') {}

    /** @return array<string, mixed> */
    public function invoke(mixed $arguments, ResolvedContext $identity, QueryCompiler $compiler): array
    {
        $args = Input::object($arguments, 'args');
        if (array_key_exists('__generation', $args) && (! is_string($args['__generation']) || ! Str::isUuid($args['__generation']))) {
            Input::fail('generation', 'Query generations must be UUIDs.');
        }

        $context = ContextDefinition::configured()->resolve(request());
        if ($context->userId() === null || $context->userId() !== $identity->userId() || $identity->values !== [] && $context->values !== $identity->values) {
            throw new AuthorizationException('Table identity differs from the authenticated Zero identity.');
        }

        $definition = resolve(TableRegistry::class)->find($this->table, $this->contract);
        $definition->bind(request()->user());

        if ($this->operation === null) {
            return $compiler->compileDeclaration($definition->rows($args), $context, serving: true);
        }

        $path = TableRelationPath::all($definition)[$this->path] ?? throw new LogicException('Missing table choice path. Regenerate.');
        abort_unless($path->queryName($definition, $this->operation, $this->bindings) === $this->queryName, 409, 'Table choices changed. Regenerate.');
        $ast = $compiler->compileDeclaration($this->choices($path, $args), $context, serving: true);
        foreach ($this->bindings as $name) {
            $binding = resolve(RegisteredQueries::class)->all()[$name] ?? null;
            if (! $binding instanceof RegisteredQuery || ! $binding->standalone || $binding->parameters !== [] || $binding->guards !== [] || $binding->contract !== $name) {
                throw new LogicException('Missing standalone choice registration. Regenerate.');
            }

            // Only input-free, unguarded standalone declarations qualify. Resolve the
            // same actor and application context for both sides of the intersection.
            $binding->assertCurrent();
            $catalog = $compiler->compileDeclaration($binding->declaration([])->withoutEagerLoads(), $context, serving: true);
            if ($catalog['table'] !== $ast['table'] || array_diff(array_keys($catalog), ['table', 'where']) !== []) {
                throw new LogicException('Invalid standalone table choice membership. Regenerate.');
            }

            if (isset($catalog['where'])) {
                $ast['where'] = isset($ast['where']) ? ['type' => 'and', 'conditions' => [$catalog['where'], $ast['where']]] : $catalog['where'];
            }
        }

        return $ast;
    }

    /** @param array<string, mixed> $args */
    private function choices(TableRelationPath $source, array $args): ZeroQuery
    {
        $choices = $source->choices ?? throw new LogicException('Missing table choices. Regenerate.');
        $model = $source->target;
        $query = new ZeroQuery($model, $model->registry);
        if ($model->policyVisibility) {
            $query->visibleTo(request()->user());
        }

        $query->where(fn (ZeroQuery $group) => $source->parent->applyRelationConstraints($source->relation, $group));
        if ($this->operation === 'list') {
            Input::keys($args, ['search', '__cursor', '__reverse', '__generation'], 'args');
            $search = $args['search'] ?? null;
            if (! is_string($search) || mb_strlen($search) > 256) {
                Input::fail('search', 'Expected up to 256 characters of search text.');
            }

            $query->where($choices['label'], 'ILIKE', OptionPattern::contains($search))->orderBy($choices['label']);
            if ($choices['label'] !== $model->key) {
                $query->orderBy($model->key);
            }

            return $query->cursorPaginate(TableRelationPath::pageSize())->atPosition(array_intersect_key($args, ['__cursor' => true, '__reverse' => true]));
        }

        if ($this->operation !== 'resolve') {
            throw new LogicException('Unknown registered table choice operation.');
        }

        Input::keys($args, ['ids', '__generation'], 'args');
        $ids = $args['ids'] ?? null;
        if (! is_array($ids) || ! array_is_list($ids) || $ids === [] || count($ids) > TableRelationPath::MAX_SELECTED_IDS) {
            Input::fail('ids', 'Expected one bounded list of selected IDs.');
        }

        foreach ($ids as $id) {
            Input::value($id, $model->columns[$model->key], 'ids');
            if (is_string($id) && ($id === '' || mb_strlen($id) > 1000)) {
                Input::fail('ids', 'Selected text IDs require 1–1000 characters.');
            }
        }

        return $query->whereIn($model->key, $ids)->limit(count($ids));
    }
}
