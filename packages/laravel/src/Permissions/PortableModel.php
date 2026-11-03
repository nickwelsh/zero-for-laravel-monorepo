<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use LogicException;

final readonly class PortableModel
{
    /** @param list<string> $policyFields
     * @param  list<list<Matcher>>  $branches
     * @param  list<string>  $conditions
     */
    public function __construct(
        public array $policyFields,
        public bool $scoped,
        public int $roleArity,
        public bool $denyOverride,
        public array $branches,
        public array $conditions,
        public string $fingerprint,
        public string $runtimeMatcher,
    ) {}

    public static function compile(string $source, Binding $binding): self
    {
        $sections = [];
        $section = '';
        foreach (explode("\n", $source) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (preg_match('/^\[([a-z_]+)\]$/D', $line, $match)) {
                $section = $match[1];

                continue;
            }

            if (! preg_match('/^([a-z][a-z0-9_]*)\s*=\s*(.+)$/D', $line, $match) || isset($sections[$section][$match[1]])) {
                throw new LogicException('Portable Casbin models require one r, p, m, e and optional g definition.');
            }

            $sections[$section][$match[1]] = $match[2];
        }

        $allowed = ['request_definition' => 'r', 'policy_definition' => 'p', 'role_definition' => 'g', 'policy_effect' => 'e', 'matchers' => 'm'];
        foreach ($sections as $name => $definitions) {
            if (! isset($allowed[$name]) || array_keys($definitions) !== [$allowed[$name]]) {
                throw new LogicException('Multiple named or custom Casbin sections are server-only.');
            }
        }

        $request = array_map(trim(...), explode(',', $sections['request_definition']['r'] ?? ''));
        if (! in_array($request, [['sub', 'obj', 'act'], ['sub', 'dom', 'obj', 'act']], true)) {
            throw new LogicException('Unsupported portable Casbin request layout.');
        }

        $scoped = in_array('dom', $request, true);
        $policy = array_map(trim(...), explode(',', $sections['policy_definition']['p'] ?? ''));
        $base = $scoped ? ['sub', 'dom', 'obj', 'act', 'cond'] : ['sub', 'obj', 'act', 'cond'];
        $roleFree = $scoped && in_array($policy, [['dom', 'obj', 'act', 'cond'], ['dom', 'obj', 'act', 'cond', 'eft']], true);
        if ($roleFree) {
            $base = ['dom', 'obj', 'act', 'cond'];
        }

        if ($policy !== $base && $policy !== [...$base, 'eft']) {
            throw new LogicException('Unsupported portable Casbin policy layout.');
        }

        $effect = preg_replace('/\s+/', '', $sections['policy_effect']['e'] ?? '');
        $deny = match ($effect) {
            'some(where(p.eft==allow))' => false,
            'some(where(p.eft==allow))&&!some(where(p.eft==deny))' => true,
            default => throw new LogicException('Only allow-only and allow-plus-deny-override Casbin effects are portable.'),
        };
        if ($deny && ! in_array('eft', $policy, true)) {
            throw new LogicException('Deny override requires an explicit eft field.');
        }

        $role = $sections['role_definition']['g'] ?? null;
        $arity = $role === null ? 0 : count(array_map(trim(...), explode(',', $role)));
        if ($role !== null && (preg_replace('/\s+/', '', $role) !== ($scoped ? '_,_,_' : '_,_') || $roleFree)) {
            throw new LogicException('Portable Casbin roles require g = _, _ or the scoped g = _, _, _.');
        }

        $matcher = Matcher::parse($sections['matchers']['m'] ?? '');
        $branches = $matcher->branches();
        $conditions = ['any'];
        foreach ($branches as $branch) {
            $required = ['r.obj.type=p.obj', 'r.act=p.act'];
            if ($scoped) {
                $required[] = 'r.dom=p.dom';
            }

            if (! $roleFree) {
                $required[] = $arity > 0 ? 'g' : 'r.sub.key=p.sub';
            }

            $found = [];
            foreach ($branch as $atom) {
                if ($atom->kind === 'role') {
                    $args = array_map(fn (Matcher $node): string => $node->field(), $atom->children);
                    if ($arity === 0 || $args !== ($scoped ? ['r.sub.key', 'p.sub', 'r.dom'] : ['r.sub.key', 'p.sub'])) {
                        throw new LogicException('Only positive g(r.sub.key, p.sub[, r.dom]) is portable.');
                    }

                    $found[] = 'g';

                    continue;
                }

                [$a, $b] = $atom->children;
                $left = self::type($a, $binding, $policy, $scoped);
                $right = self::type($b, $binding, $policy, $scoped);
                if ($left !== $right || ($left !== 'integer' && ! in_array($atom->kind, ['==', '!='], true))) {
                    throw new LogicException('Portable Casbin predicates require identical scalar types; ordering requires bounded integers.');
                }

                if ($a->kind === 'field' && $b->kind === 'field') {
                    $fields = [$a->field(), $b->field()];
                    $objectFields = array_filter($fields, fn (string $field): bool => str_starts_with($field, 'r.obj.') && $field !== 'r.obj.type');
                    $mutableSubject = array_filter($fields, fn (string $field): bool => str_starts_with($field, 'r.sub.') && ! in_array($field, ['r.sub.key', 'r.sub.id'], true));
                    if (count($objectFields) > 1 || ($objectFields !== [] && $mutableSubject !== [] && $atom->kind !== '==')) {
                        throw new LogicException('Zero 1.11.3 cannot represent this live cross-row inequality or resource-column comparison.');
                    }

                    $dynamic = array_values(array_filter($fields, fn (string $field): bool => ! in_array($field, ['r.sub.key', 'r.sub.id', 'r.obj.type', 'r.act'], true)));
                    if (count($dynamic) === 2) {
                        $aResource = str_starts_with($dynamic[0], 'r.obj.') || $dynamic[0] === 'r.dom';
                        $bResource = str_starts_with($dynamic[1], 'r.obj.') || $dynamic[1] === 'r.dom';
                        if ($aResource === $bResource || $atom->kind !== '==') {
                            throw new LogicException('Zero 1.11.3 supports equality correlations, not comparisons between columns on the same row.');
                        }
                    }

                    if ($atom->kind === '==') {
                        $found[] = $a->field().'='.$b->field();
                        $found[] = $b->field().'='.$a->field();
                    }

                    // Only domains and resource/subject equality may become column correlations.
                    if (! self::allowedCorrelation($fields) && $objectFields !== []) {
                        throw new LogicException('Unsupported portable cross-column Casbin comparison.');
                    }
                }

                if ($a->kind === 'field' && $a->value === 'p.cond' && $b->kind === 'literal' && is_string($b->value)) {
                    $conditions[] = $b->value;
                }

                if ($b->kind === 'field' && $b->value === 'p.cond' && $a->kind === 'literal' && is_string($a->value)) {
                    $conditions[] = $a->value;
                }
            }

            if (array_diff($required, $found) !== []) {
                throw new LogicException('Every portable matcher branch must constrain subject/roles, domain, resource type and action as declared.');
            }
        }

        return new self($policy, $scoped, $arity, $deny, $branches, array_values(array_unique($conditions)), hash('sha256', $source), $matcher->strictExpression());
    }

    /** @param list<string> $policy */
    private static function type(Matcher $node, Binding $binding, array $policy, bool $scoped): string
    {
        if ($node->kind === 'literal') {
            return match (true) {
                is_string($node->value) => 'string', is_int($node->value) => 'integer', is_bool($node->value) => 'boolean',
                default => throw new LogicException('Null is not a portable Casbin attribute.'),
            };
        }

        $field = $node->field();
        if (str_starts_with($field, 'p.')) {
            if (! in_array(substr($field, 2), $policy, true)) {
                throw new LogicException("Undefined Casbin policy field {$field}.");
            }

            return 'string';
        }

        if (in_array($field, ['r.sub.key', 'r.sub.id', 'r.obj.type', 'r.obj.id', 'r.act'], true) || ($scoped && $field === 'r.dom')) {
            return 'string';
        }

        $attributes = str_starts_with($field, 'r.sub.') ? Binding::attributes(config()->get('permissions.subject.attributes')) : $binding->attributes;
        $name = substr($field, 6);
        $type = $attributes[$name]['type'] ?? throw new LogicException("Unmapped or non-database Casbin attribute {$field}.");

        return $type === 'id' ? 'string' : $type;
    }

    /** @param list<string> $fields */
    private static function allowedCorrelation(array $fields): bool
    {
        return count(array_filter($fields, fn (string $field): bool => str_starts_with($field, 'r.sub.'))) === 1;
    }
}
