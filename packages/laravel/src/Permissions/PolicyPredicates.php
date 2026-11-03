<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use LogicException;

/**
 * @phpstan-type Operand array{kind: 'literal', value: string|int|bool}|array{kind: 'resource'|'grant', column: string}
 * @phpstan-type Comparison array{column: string, operator: string, value: string|int|bool}
 * @phpstan-type Branch array{resource: list<Comparison>, grant: list<Comparison>, correlations: array<string, string>}
 */
final readonly class PolicyPredicates
{
    /** @param list<Branch> $branches */
    public function __construct(public PolicyPlan $plan, public string $subjectKey, public array $branches) {}

    public static function bind(PolicyPlan $plan, string $subjectId): self
    {
        $subjectKey = config()->string('permissions.subject.prefix').$subjectId;
        $branches = [];
        foreach ($plan->model->branches as $atoms) {
            $branch = ['resource' => [], 'grant' => [], 'correlations' => ['global_key' => 'global_key']];
            foreach ($atoms as $atom) {
                if ($atom->kind === 'role') {
                    // The transactional index only contains positively reachable grants.
                    continue;
                }

                $a = self::operand($atom->children[0], $plan, $subjectId, $subjectKey);
                $b = self::operand($atom->children[1], $plan, $subjectId, $subjectKey);
                $operator = $atom->kind === '==' ? '=' : $atom->kind;
                if ($a['kind'] === 'literal' && $b['kind'] === 'literal') {
                    $matches = match ($operator) {
                        '=' => $a['value'] === $b['value'], '!=' => $a['value'] !== $b['value'],
                        '<' => $a['value'] < $b['value'], '<=' => $a['value'] <= $b['value'],
                        '>' => $a['value'] > $b['value'], '>=' => $a['value'] >= $b['value'],
                        default => throw new LogicException('Invalid portable comparison.'),
                    };
                    if (! $matches) {
                        continue 2;
                    }
                } elseif ($a['kind'] !== 'literal' && $b['kind'] !== 'literal') {
                    if ($operator !== '=' || $a['kind'] === $b['kind']) {
                        throw new LogicException('Unsupported live column correlation.');
                    }

                    $resource = $a['kind'] === 'resource' ? $a['column'] : $b['column'];
                    $grant = $a['kind'] === 'grant' ? $a['column'] : $b['column'];
                    if (isset($branch['correlations'][$resource]) && $branch['correlations'][$resource] !== $grant) {
                        throw new LogicException('A portable branch cannot correlate one resource column to multiple grant columns.');
                    }

                    $branch['correlations'][$resource] = $grant;
                } else {
                    $column = $a['kind'] === 'literal' ? $b : $a;
                    $literal = $a['kind'] === 'literal' ? $a : $b;
                    if ($column['kind'] === 'literal' || $literal['kind'] !== 'literal') {
                        throw new LogicException('Invalid bound permission operands.');
                    }

                    if ($a['kind'] === 'literal') {
                        $operator = match ($operator) {
                            '<' => '>', '<=' => '>=', '>' => '<', '>=' => '<=', default => $operator
                        };
                    }

                    $branch[$column['kind']][] = ['column' => $column['column'], 'operator' => $operator, 'value' => $literal['value']];
                }
            }

            $branches[] = $branch;
        }

        return new self($plan, $subjectKey, $branches);
    }

    /** @return Operand */
    private static function operand(Matcher $node, PolicyPlan $plan, string $subjectId, string $subjectKey): array
    {
        if ($node->kind === 'literal' && $node->value !== null) {
            return ['kind' => 'literal', 'value' => $node->value];
        }

        $field = $node->field();
        $literal = match ($field) {
            'r.sub.id' => $subjectId, 'r.sub.key' => $subjectKey, 'r.act' => $plan->action, 'r.obj.type' => $plan->binding->type,
            default => null,
        };
        if ($literal !== null) {
            return ['kind' => 'literal', 'value' => $literal];
        }

        if (str_starts_with($field, 'p.')) {
            $index = array_search(substr($field, 2), $plan->model->policyFields, true);
            if ($index === false) {
                throw new LogicException('Undefined portable policy field.');
            }

            return ['kind' => 'grant', 'column' => 'v'.$index];
        }

        if (str_starts_with($field, 'r.sub.')) {
            return ['kind' => 'grant', 'column' => 's_'.substr($field, 6)];
        }

        return ['kind' => 'resource', 'column' => match ($field) {
            'r.dom' => 'domain_key', 'r.obj.id' => 'object_id', default => 'a_'.substr($field, 6),
        }];
    }
}
