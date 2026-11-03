<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use LogicException;

final class RoleGraph
{
    /** @var array<string, list<string>> */
    private array $parents = [];

    /** @param list<array{id: string, ptype: string, fields: list<string>}> $rules */
    public function __construct(array $rules)
    {
        $prefix = config()->string('permissions.subject.prefix');
        foreach ($rules as $rule) {
            if ($rule['ptype'] !== 'g') {
                continue;
            }

            [$child, $parent] = $rule['fields'];
            $domain = $rule['fields'][2] ?? '__global__';
            if ((! str_starts_with($child, $prefix) && ! str_starts_with($child, 'role:')) || ! str_starts_with($parent, 'role:')) {
                throw new LogicException('Grouping rules must assign configured user principals or roles to role: principals.');
            }

            $this->parents[$domain."\0".$child][] = $parent;
        }

        foreach (array_keys($this->parents) as $key) {
            [$domain, $principal] = explode("\0", $key, 2);
            $this->walk($principal, $domain, []);
        }
    }

    /** @return list<string> */
    public function reachable(string $principal, string $domain): array
    {
        return array_values(array_unique($this->walk($principal, $domain, [])));
    }

    /** @param list<string> $path
     * @return list<string>
     */
    private function walk(string $principal, string $domain, array $path): array
    {
        if (in_array($principal, $path, true)) {
            throw new LogicException('Casbin role inheritance must be acyclic.');
        }

        if (count($path) > 8) {
            throw new LogicException('Casbin role inheritance exceeds eight edges.');
        }

        $roles = [$principal];
        foreach ($this->parents[$domain."\0".$principal] ?? [] as $parent) {
            $roles = [...$roles, ...$this->walk($parent, $domain, [...$path, $principal])];
            if (count($roles) > 10000) {
                throw new LogicException('Casbin role expansion exceeds the supported fan-out budget.');
            }
        }

        return $roles;
    }
}
