<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Query\Builder;
use ZeroForLaravel\Query\Condition;
use ZeroForLaravel\Query\ExpressionBuilder;

/** Three translations of the same class predicates; none read a business resource row. */
final readonly class ClassPermission
{
    private PolicyPredicates $predicates;

    public function __construct(private PolicyPlan $plan, string $subjectId, private string $domain)
    {
        $this->predicates = PolicyPredicates::bind($plan, $subjectId);
    }

    /** Scalar Casbin inspection deliberately does not dispatch Laravel Gate again. */
    public function inspect(Authenticatable $user): bool
    {
        $manager = resolve(PermissionManager::class)->model($this->plan->binding->model);
        // Preserve native model fingerprint validation even without installed projections.
        $manager->enforcer($this->plan->binding);

        $rules = $manager->rules();
        $graph = new RoleGraph($rules);
        $subject = (array) Binding::subject($user);
        if ($subject['key'] !== $this->predicates->subjectKey) {
            return false;
        }

        $allow = false;
        foreach ($rules as $rule) {
            if ($rule['ptype'] !== 'p') {
                continue;
            }

            $values = array_combine($this->plan->model->policyFields, $rule['fields']);
            if (isset($values['sub']) && ! in_array($values['sub'], $graph->reachable($this->predicates->subjectKey,
                $this->plan->model->scoped ? $values['dom'] : '__global__'), true)) {
                continue;
            }

            $grant = ['global_key' => 'global'];
            foreach ($rule['fields'] as $index => $field) {
                $grant['v'.$index] = $field;
            }

            foreach ($subject as $name => $value) {
                if (is_string($value) || is_int($value) || is_bool($value)) {
                    $grant['s_'.$name] = $value;
                }
            }

            $effect = $values['eft'] ?? 'allow';
            if (! in_array($effect, ['allow', 'deny'], true) || ($effect === 'deny' && ! $this->plan->model->denyOverride)) {
                continue;
            }

            foreach ($this->predicates->classGrants($this->domain, $effect === 'deny') as $branch) {
                foreach ($branch as $comparison) {
                    if (! isset($grant[$comparison['column']]) || ! PolicyPredicates::compare($grant[$comparison['column']], $comparison['operator'], $comparison['value'])) {
                        continue 2;
                    }
                }

                if ($effect === 'deny') {
                    return false;
                }

                $allow = true;
            }
        }

        return $allow;
    }

    /** Authoritative maintained decision used when capturing a live dependency. */
    public function query(): Builder
    {
        return (new ProjectionManager)->connection()->query()->selectRaw('1')->from('permission_subjects')
            ->where('id', $this->predicates->subjectKey)
            ->where(function (Builder $decision): void {
                if ($this->plan->adminAttribute !== null) {
                    $decision->where('s_'.$this->plan->adminAttribute, true);
                }

                $decision->orWhere(function (Builder $casbin): void {
                    if ($this->plan->constant !== null) {
                        $casbin->whereRaw($this->plan->constant ? '1 = 1' : '1 = 0');

                        return;
                    }

                    $casbin->whereExists($this->grants('allow'));
                    if ($this->plan->model->denyOverride) {
                        $casbin->whereNotExists($this->grants('deny'));
                    }
                });
            });
    }

    /** Isolated status-row filter; permission attributes never enter the browser schema. */
    public function condition(bool $allowed): Condition
    {
        $allow = $this->plan->constant === null
            ? $this->exists('permission_index', $this->grantCondition('allow'))
            : new Condition($this->plan->constant ? 'and' : 'or');
        if ($this->plan->constant === null && $this->plan->model->denyOverride) {
            $allow = new Condition('and', conditions: [$allow, $this->exists('permission_index', $this->grantCondition('deny'), 'NOT EXISTS')]);
        }

        if ($this->plan->adminAttribute !== null) {
            $allow = new Condition('or', conditions: [$this->comparison('s_'.$this->plan->adminAttribute, '=', true), $allow]);
        }

        return $this->exists('permission_subjects', ExpressionBuilder::simplify(new Condition('and', conditions: [
            $this->comparison('id', '=', $this->predicates->subjectKey), $allow,
        ])), $allowed ? 'EXISTS' : 'NOT EXISTS');
    }

    private function grants(string $effect): Builder
    {
        return (new ProjectionManager)->connection()->query()->selectRaw('1')->from('permission_index')
            ->where('model', $this->plan->binding->model)->where('subject_key', $this->predicates->subjectKey)->where('effect', $effect)
            ->where(function (Builder $alternatives) use ($effect): void {
                $alternatives->whereRaw('1 = 0');
                foreach ($this->predicates->classGrants($this->domain, $effect === 'deny') as $branch) {
                    $alternatives->orWhere(function (Builder $match) use ($branch): void {
                        foreach ($branch as $comparison) {
                            $match->where($comparison['column'], $comparison['operator'], $comparison['value']);
                        }
                    });
                }
            });
    }

    private function grantCondition(string $effect): Condition
    {
        $branches = [];
        foreach ($this->predicates->classGrants($this->domain, $effect === 'deny') as $branch) {
            $branches[] = new Condition('and', conditions: array_map(fn (array $comparison): Condition => $this->comparison($comparison['column'], $comparison['operator'], $comparison['value']), $branch));
        }

        return new Condition('and', conditions: [
            $this->comparison('model', '=', $this->plan->binding->model),
            $this->comparison('subject_key', '=', $this->predicates->subjectKey),
            $this->comparison('effect', '=', $effect), new Condition('or', conditions: $branches),
        ]);
    }

    private function exists(string $table, Condition $condition, string $operator = 'EXISTS'): Condition
    {
        return new Condition('correlatedSubquery', ['op' => $operator, 'related' => [
            'system' => 'permissions', 'hidden' => true,
            'correlation' => ['parentField' => ['global_key'], 'childField' => ['global_key']],
            'subquery' => ['table' => $table, 'alias' => 'zsubq_class_'.$table, 'where' => $condition->toArray()],
        ]]);
    }

    private function comparison(string $column, string $operator, string|int|bool $value): Condition
    {
        return new Condition('simple', ['op' => $operator, 'left' => ['type' => 'column', 'name' => $column], 'right' => ['type' => 'literal', 'value' => $value]]);
    }
}
