<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use LogicException;
use stdClass;

/** Optional, current-subject UI hints. This feed never replaces authoritative Gates. */
final class Predictions
{
    /**
     * @param  list<array{resource: class-string<Model>, ability: string}>  $abilities
     * @return array{version: int, subject: array{id: string, key: string, attributes: stdClass}, resources: stdClass}
     */
    public static function forUser(Authenticatable $user, array $abilities): array
    {
        $class = config()->string('permissions.subject.class');
        if (! $user instanceof Model || ! $user instanceof $class) {
            throw new LogicException('Permission predictions require the configured authenticated Eloquent subject.');
        }

        $projector = new ProjectionManager;
        $projector->assertConnection($user);

        return $projector->connection()->transaction(function () use ($user, $abilities, $projector): array {
            $projector->lock();
            $projector->assertReady();

            $fresh = $user->fresh();
            if (! $fresh instanceof Authenticatable) {
                throw new LogicException('Permission prediction subjects must still exist.');
            }

            $subject = Binding::subject($fresh);
            $subjectId = Binding::id($fresh->getAuthIdentifier());
            $subjectKey = config()->string('permissions.subject.prefix').$subjectId;
            $subjectBindings = Binding::attributes(config()->get('permissions.subject.attributes'));
            $exposed = self::names(config()->get('permissions.predictions.subject_attributes', []));
            $facts = [];
            foreach ($exposed as $name) {
                if (! isset($subjectBindings[$name])) {
                    throw new LogicException('Prediction subject attributes must name configured permission attributes.');
                }

                $facts[$name] = $subject->{$name};
            }

            $approved = config()->get('permissions.predictions.abilities', []);
            $resourceAttributes = config()->get('permissions.predictions.resource_attributes', []);
            if (! is_array($approved) || ! is_array($resourceAttributes)) {
                throw new LogicException('Prediction abilities and resource attributes must be class-keyed maps.');
            }

            $resources = [];
            foreach ($abilities as $request) {
                $resource = $request['resource'];
                $ability = $request['ability'];
                if (! in_array($ability, self::names($approved[$resource] ?? []), true)) {
                    continue;
                }

                $plan = (new PolicyCompiler)->compile($resource, $ability);
                $projector->assertConnection(new $resource);
                $bound = PolicyPredicates::bind($plan, $subjectId);
                $descriptor = self::descriptor($bound, $exposed, self::names($resourceAttributes[$resource] ?? []));
                if ($descriptor !== null) {
                    $resources[$resource][$ability] = $descriptor;
                }
            }

            return ['version' => 1, 'subject' => ['id' => $subjectId, 'key' => $subjectKey, 'attributes' => (object) $facts],
                'resources' => (object) array_map(static fn (array $descriptors): stdClass => (object) $descriptors, $resources)];
        });
    }

    /**
     * @param  list<string>  $subjectAttributes
     * @param  list<string>  $resourceAttributes
     * @return array<string, mixed>|null
     */
    private static function descriptor(PolicyPredicates $bound, array $subjectAttributes, array $resourceAttributes): ?array
    {
        $plan = $bound->plan;
        $branches = $plan->constant === null ? $bound->branches : [];
        $resourceColumns = [];
        $grantColumns = [];
        foreach ($branches as &$branch) {
            // Both sides of this synthetic correlation are the same fixed constant.
            unset($branch['correlations']['global_key']);
            $resourceColumns = [...$resourceColumns, ...array_column($branch['resource'], 'column'), ...array_keys($branch['correlations'])];
            $grantColumns = [...$grantColumns, ...array_column($branch['grant'], 'column'), ...array_values($branch['correlations'])];
        }

        unset($branch);

        $subjectBindings = Binding::attributes(config()->get('permissions.subject.attributes'));
        $subjectTypes = [];
        foreach (array_unique($grantColumns) as $column) {
            if (str_starts_with($column, 's_')) {
                $name = substr($column, 2);
                if (! in_array($name, $subjectAttributes, true) || ! isset($subjectBindings[$name])) {
                    return null;
                }

                $subjectTypes[$name] = $subjectBindings[$name]['type'];
            }
        }

        if ($plan->adminAttribute !== null) {
            if (! in_array($plan->adminAttribute, $subjectAttributes, true)) {
                return null;
            }

            $subjectTypes[$plan->adminAttribute] = 'boolean';
        }

        // Reachable roles are already expanded in the index. Never publish their names.
        $principal = array_search('sub', $plan->model->policyFields, true);
        if ($plan->model->roleArity > 0 && $principal !== false && in_array('v'.$principal, $grantColumns, true)) {
            return null;
        }

        $fields = [];
        foreach (array_unique($resourceColumns) as $column) {
            $field = self::resourceField($plan->binding, $column, $resourceAttributes);
            if ($field === null) {
                return null;
            }

            $fields[$column] = $field;
        }

        return ['model' => $plan->binding->model, 'type' => $plan->binding->type, 'action' => $plan->action,
            'denyOverride' => $plan->model->denyOverride, 'admin' => $plan->adminAttribute, 'constant' => $plan->constant,
            'subjectAttributes' => (object) $subjectTypes, 'resourceFields' => (object) $fields,
            'branches' => array_map(static fn (array $branch): array => [...$branch, 'correlations' => (object) $branch['correlations']], $branches),
            'grants' => self::grants($bound, array_values(array_unique($grantColumns)))];
    }

    /**
     * @param  list<string>  $approved
     * @return array{column: string, type: string, prefix: string}|array{value: string}|null
     */
    private static function resourceField(Binding $binding, string $column, array $approved): ?array
    {
        if ($column === 'domain_key' && $binding->domainColumn === null) {
            return ['value' => '__global__'];
        }

        if ($column === 'domain_key' || $column === 'object_id') {
            $source = $column === 'domain_key' ? $binding->domainColumn : (new $binding->class)->getKeyName();

            return $source !== null && in_array($source, $approved, true)
                ? ['column' => $source, 'type' => 'id', 'prefix' => $column === 'domain_key' ? $binding->domainPrefix : ''] : null;
        }

        $name = str_starts_with($column, 'a_') ? substr($column, 2) : '';
        $attribute = $binding->attributes[$name] ?? null;

        return $attribute !== null && in_array($name, $approved, true)
            ? ['column' => $attribute['column'], 'type' => $attribute['type'], 'prefix' => ''] : null;
    }

    /**
     * @param  list<string>  $columns
     * @return list<array{effect: string, values: stdClass}>
     */
    private static function grants(PolicyPredicates $bound, array $columns): array
    {
        if ($bound->plan->constant !== null || $bound->branches === []) {
            return [];
        }

        $query = resolve(PermissionManager::class)->connection()->table('permission_index')
            ->where('subject_key', $bound->subjectKey)->where('model', $bound->plan->binding->model)
            ->whereIn('effect', $bound->plan->model->denyOverride ? ['allow', 'deny'] : ['allow'])
            ->where(function (Builder $alternatives) use ($bound): void {
                foreach ($bound->branches as $branch) {
                    $alternatives->orWhere(function (Builder $grant) use ($branch): void {
                        foreach ($branch['grant'] as $comparison) {
                            $grant->where($comparison['column'], $comparison['operator'], $comparison['value']);
                        }
                    });
                }
            });
        $grants = [];
        $seen = [];
        foreach ($query->select(['effect', ...$columns])->orderBy('id')->get() as $row) {
            $values = [];
            foreach ($columns as $column) {
                $value = $row->{$column};
                $type = str_starts_with($column, 's_')
                    ? Binding::attributes(config()->get('permissions.subject.attributes'))[substr($column, 2)]['type'] : 'string';
                $values[$column] = match ($type) {
                    'id' => Binding::id($value),
                    'boolean' => is_bool($value) ? $value : throw new LogicException('Invalid projected prediction boolean.'),
                    'integer' => is_int($value) && abs($value) <= 9007199254740991 ? $value : throw new LogicException('Invalid projected prediction integer.'),
                    default => is_string($value) ? $value : throw new LogicException('Invalid projected prediction string.'),
                };
            }

            if (! is_string($row->effect) || ! in_array($row->effect, ['allow', 'deny'], true)) {
                throw new LogicException('Invalid projected prediction effect.');
            }

            $grant = ['effect' => $row->effect, 'values' => (object) $values];
            $key = json_encode($grant, JSON_THROW_ON_ERROR);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $grants[] = $grant;
            }
        }

        return $grants;
    }

    /** @return list<string> */
    private static function names(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Prediction allowlists must be lists of attribute or ability names.');
        }

        foreach ($value as $name) {
            if (! is_string($name) || $name === '') {
                throw new LogicException('Prediction allowlists require nonempty string names.');
            }
        }

        return $value;
    }
}
