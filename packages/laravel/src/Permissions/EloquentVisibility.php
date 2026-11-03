<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as Sql;
use LogicException;

/** @phpstan-import-type Branch from PolicyPredicates
 * @phpstan-import-type Comparison from PolicyPredicates
 */
final class EloquentVisibility
{
    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function apply(Builder $query, Authenticatable $user): Builder
    {
        $plan = (new PolicyCompiler)->compile($query->getModel()::class);
        $projector = new ProjectionManager;
        $projector->assertReady();
        $projector->assertConnection($query->getModel());
        if (! $user instanceof Model || ! is_a($user, config()->string('permissions.subject.class'))) {
            throw new LogicException('visibleTo requires the configured Eloquent user class.');
        }

        $projector->assertConnection($user);

        $table = $query->getModel()->getTable();
        $source = (new ($query->getModel()::class))->getTable();
        $selfRelation = preg_match('/^laravel_reserved_[0-9]+$/D', $table)
            && $query->getQuery()->from === $source.' as '.$table;
        if ($query->getQuery()->from !== $table && ! $selfRelation) {
            throw new LogicException('Portable visibility does not support custom FROM expressions or aliases.');
        }

        $predicates = PolicyPredicates::bind($plan, Binding::id($user->getAuthIdentifier()));
        $key = $query->getModel()->qualifyColumn($query->getModel()->getKeyName());

        return $query->whereExists(function (Sql $resource) use ($plan, $predicates, $key): void {
            $resource->selectRaw('1')->from($plan->binding->projectionTable(), 'permission_resource')
                ->whereColumn('permission_resource.resource_id', $key)
                ->where(function (Sql $decision) use ($plan, $predicates): void {
                    if ($plan->adminAttribute !== null) {
                        $decision->whereExists(function (Sql $subject) use ($plan, $predicates): void {
                            $subject->selectRaw('1')->from('permission_subjects', 'permission_subject')
                                ->whereColumn('permission_subject.global_key', 'permission_resource.global_key')
                                ->where('permission_subject.id', $predicates->subjectKey)
                                ->where('permission_subject.s_'.$plan->adminAttribute, true);
                        });
                    }

                    $decision->orWhere(function (Sql $casbin) use ($plan, $predicates): void {
                        if ($plan->constant !== null) {
                            $casbin->whereRaw($plan->constant ? '1 = 1' : '1 = 0');

                            return;
                        }

                        $this->matches($casbin, $predicates, 'allow');
                        if ($plan->model->denyOverride) {
                            $casbin->whereNot(function (Sql $denied) use ($predicates): void {
                                $this->matches($denied, $predicates, 'deny');
                            });
                        }
                    });
                });
        });
    }

    private function matches(Sql $query, PolicyPredicates $predicates, string $effect): void
    {
        $query->where(function (Sql $alternatives) use ($predicates, $effect): void {
            if ($predicates->branches === []) {
                $alternatives->whereRaw('1 = 0');
            }

            foreach ($predicates->branches as $branch) {
                $alternatives->orWhere(function (Sql $match) use ($predicates, $effect, $branch): void {
                    $this->comparisons($match, 'permission_resource', $branch['resource']);
                    $match->whereExists(function (Sql $grant) use ($predicates, $effect, $branch): void {
                        $grant->selectRaw('1')->from('permission_index', 'permission_grant')
                            ->where('permission_grant.model', $predicates->plan->binding->model)
                            ->where('permission_grant.subject_key', $predicates->subjectKey)
                            ->where('permission_grant.effect', $effect);
                        foreach ($branch['correlations'] as $resource => $field) {
                            $grant->whereColumn('permission_grant.'.$field, 'permission_resource.'.$resource);
                        }

                        $this->comparisons($grant, 'permission_grant', $branch['grant']);
                    });
                });
            }
        });
    }

    /** @param list<Comparison> $comparisons */
    private function comparisons(Sql $query, string $alias, array $comparisons): void
    {
        foreach ($comparisons as $comparison) {
            $query->where($alias.'.'.$comparison['column'], $comparison['operator'], $comparison['value']);
        }
    }
}
