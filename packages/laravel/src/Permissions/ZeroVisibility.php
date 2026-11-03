<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Eloquent\Model;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\ExpressionBuilder;

/** Native Zero lowering of the same bound predicates used by Eloquent. */
final class ZeroVisibility
{
    private int $alias = 0;

    /** @param list<array{class: class-string<Model>, id: string, ability: string}> $guards */
    public function condition(ModelMetadata $model, string $subjectId, string $ability = 'view', array $guards = []): Condition
    {
        $plan = (new PolicyCompiler)->compile($model->class, $ability);
        $conditions = [$this->decision($plan, $subjectId)];
        foreach ($guards as $guard) {
            $guardPlan = (new PolicyCompiler)->compile($guard['class'], $guard['ability']);
            $conditions[] = $this->exists($guardPlan->binding->projectionTable(), ['global_key' => 'global_key'], new Condition('and', conditions: [
                $this->comparison('object_id', '=', $guard['id']), $this->decision($guardPlan, $subjectId),
            ]));
        }

        return $this->exists($plan->binding->projectionTable(), [$model->key => 'resource_id'], ExpressionBuilder::simplify(new Condition('and', conditions: $conditions)));
    }

    private function decision(PolicyPlan $plan, string $subjectId): Condition
    {
        $predicates = PolicyPredicates::bind($plan, $subjectId);
        $allow = $plan->constant === null ? $this->matches($predicates, 'allow') : new Condition($plan->constant ? 'and' : 'or');
        if ($plan->constant === null && $plan->model->denyOverride) {
            $deny = $this->exists($plan->binding->projectionTable(), ['resource_id' => 'resource_id'], $this->matches($predicates, 'deny'), 'NOT EXISTS');
            $allow = new Condition('and', conditions: [$allow, $deny]);
        }

        if ($plan->adminAttribute !== null) {
            $admin = $this->exists('permission_subjects', ['global_key' => 'global_key'], new Condition('and', conditions: [
                $this->comparison('id', '=', $predicates->subjectKey), $this->comparison('s_'.$plan->adminAttribute, '=', true),
            ]));
            $allow = new Condition('or', conditions: [$admin, $allow]);
        }

        return ExpressionBuilder::simplify($allow);
    }

    private function matches(PolicyPredicates $predicates, string $effect): Condition
    {
        $branches = [];
        foreach ($predicates->branches as $branch) {
            $grant = [
                $this->comparison('model', '=', $predicates->plan->binding->model),
                $this->comparison('subject_key', '=', $predicates->subjectKey),
                $this->comparison('effect', '=', $effect),
            ];
            foreach ($branch['grant'] as $comparison) {
                $grant[] = $this->comparison($comparison['column'], $comparison['operator'], $comparison['value']);
            }

            $conditions = [];
            foreach ($branch['resource'] as $comparison) {
                $conditions[] = $this->comparison($comparison['column'], $comparison['operator'], $comparison['value']);
            }

            $conditions[] = $this->exists('permission_index', $branch['correlations'], new Condition('and', conditions: $grant));
            $branches[] = new Condition('and', conditions: $conditions);
        }

        return ExpressionBuilder::simplify(new Condition('or', conditions: $branches));
    }

    /** @param array<string, string> $correlations */
    private function exists(string $table, array $correlations, Condition $condition, string $operator = 'EXISTS'): Condition
    {
        return new Condition('correlatedSubquery', ['op' => $operator, 'related' => [
            'system' => 'permissions', 'hidden' => true,
            'correlation' => ['parentField' => array_keys($correlations), 'childField' => array_values($correlations)],
            'subquery' => ['table' => $table, 'alias' => 'zsubq_permission_'.++$this->alias, 'where' => $condition->toArray()],
        ]]);
    }

    private function comparison(string $column, string $operator, string|int|bool $value): Condition
    {
        return new Condition('simple', ['op' => $operator, 'left' => ['type' => 'column', 'name' => $column], 'right' => ['type' => 'literal', 'value' => $value]]);
    }
}
