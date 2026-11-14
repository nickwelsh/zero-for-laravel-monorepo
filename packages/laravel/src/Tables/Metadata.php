<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use LogicException;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Group discovery and counts are an internally consistent snapshot, not live Zero aggregates.
 * @phpstan-import-type Path from Table
 */
final class Metadata
{
    /** @param Path $path */
    public static function groupId(array $path): string
    {
        return 'g:'.json_encode($path, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS);
    }

    /** @param array<string, mixed> $input
     * @return array{version: int, requestKey: string, observedAt: string, totalCount: int, directories: list<array{path: Path, groups: list<array{id: string, value: string|int|float|bool|null, label: string, count: int}>, nextCursor: string|null}>}
     */
    public function read(TableDefinition $definition, ResolvedContext $context, ?Authenticatable $actor, array $input): array
    {
        Input::keys($input, ['version', 'contract', 'filter', 'groupBy', 'directories'], 'metadata');
        if (($input['version'] ?? null) !== 2) {
            Input::fail('version', 'Unsupported table protocol.');
        }

        $table = $definition->bind($actor);
        $groupBy = $table->grouping($input['groupBy'] ?? []);
        $filter = $input['filter'] ?? null;
        $requests = $input['directories'] ?? null;
        if (! is_array($requests) || ! array_is_list($requests) || count($requests) > 16) {
            Input::fail('directories', 'Request at most 16 group directories.');
        }

        $directories = [];
        foreach ($requests as $request) {
            $request = Input::object($request, 'directory');
            Input::keys($request, ['path', 'after'], 'directory');
            $path = $table->path($request['path'] ?? null, $groupBy);
            if (count($path) >= count($groupBy)) {
                Input::fail('path', 'Directories require a strict group prefix.');
            }

            $after = $request['after'] ?? null;
            if ($after !== null && (! is_string($after) || strlen($after) > 16384)) {
                Input::fail('after', 'Invalid directory cursor.');
            }

            $directories[] = ['path' => $path, 'after' => $after];
        }

        $connection = $table->base->model->registry->connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->transactionLevel() !== 0) {
            throw new LogicException('Table snapshots require a top-level PostgreSQL transaction.');
        }

        return $connection->transaction(function () use ($connection, $definition, $table, $context, $actor, $filter, $groupBy, $directories): array {
            $connection->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
            $total = $table->sql($context, $filter)->count();
            $results = [];
            foreach ($directories as $directory) {
                $path = $directory['path'];
                $rule = $groupBy[count($path)];
                $dimension = $rule['field'];
                $direction = $rule['direction'];
                $group = $table->groups[$dimension];
                $field = $group['field'];
                $column = $table->base->model->columns[$field];
                $qualified = $table->base->model->table.'.'.$field;
                $query = $table->sql($context, $filter, $path);
                $scope = hash('sha256', serialize([$definition->contract, $context->userId(), $context->values, $filter, $groupBy, $path]));
                if ($directory['after'] !== null) {
                    $after = $this->cursor($directory['after'], $scope);
                    Input::value($after, [...$column, 'nullable' => false], 'after');
                    // Both directions put null last; null can only finish the final page.
                    $query->where(fn (Builder $next) => $next->where($qualified, $direction === 'asc' ? '>' : '<', $after)->orWhereNull($qualified));
                }

                $nulls = new readonly class($qualified) implements Expression
                {
                    public function __construct(private string $column) {}

                    public function getValue(Grammar $grammar): string
                    {
                        return $grammar->wrap($this->column).' IS NULL';
                    }
                };
                $rows = $query->select($qualified)->selectRaw('COUNT(*) AS aggregate')->groupBy($qualified)->orderBy($nulls)
                    ->orderBy($qualified, $direction)->limit(51)->get();
                $more = $rows->count() > 50;
                $rows = $rows->take(50);
                $labels = [];
                if ($group['model'] !== null && $group['label'] !== null) {
                    $target = $table->base->model->registry->get($group['model']);
                    $labelsTable = Table::for($target->class);
                    if ($target->policyVisibility) {
                        $labelsTable->visibleTo($actor);
                    }

                    $labels = $labelsTable->sql($context)->whereIn($target->table.'.'.$target->key, $rows->pluck($field)->filter(fn (mixed $value): bool => $value !== null)->all())
                        ->pluck($target->table.'.'.$group['label'], $target->table.'.'.$target->key)->all();
                }

                $groups = [];
                $last = null;
                foreach ($rows as $row) {
                    $value = $row->{$field};
                    $value = $value === null ? null : match ($column['type']) {
                        'boolean' => (bool) $value,
                        'number' => is_numeric($value) ? $value + 0 : throw new LogicException('Invalid numeric directory key.'),
                        default => is_string($value) ? $value : throw new LogicException('Invalid text directory key.'),
                    };
                    Input::value($value, $column, 'group');
                    $child = [...$path, ['field' => $dimension, 'value' => $value]];
                    $label = $value === null ? 'Unassigned' : ($group['relation'] === null
                        ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value)
                        : ($labels[(string) $value] ?? 'Unavailable'));
                    if (! is_string($label)) {
                        throw new LogicException('Invalid table relation label.');
                    }

                    $count = $row->aggregate;
                    if (! is_numeric($count)) {
                        throw new LogicException('Invalid group count.');
                    }

                    $groups[] = ['id' => self::groupId($child), 'value' => $value, 'label' => $label, 'count' => (int) $count];
                    $last = $value;
                }

                $results[] = ['path' => $path, 'groups' => $groups,
                    'nextCursor' => $more && $last !== null ? Crypt::encryptString(json_encode(['scope' => $scope, 'after' => $last], JSON_THROW_ON_ERROR)) : null];
            }

            return ['version' => 2, 'requestKey' => hash('sha256', serialize([$filter, $groupBy, $directories])),
                'observedAt' => now('UTC')->toIso8601String(), 'totalCount' => $total, 'directories' => $results];
        });
    }

    private function cursor(string $cursor, string $scope): mixed
    {
        try {
            $data = Input::object(json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR), 'after');
        } catch (DecryptException|JsonException) {
            Input::fail('after', 'Invalid directory cursor.');
        }

        if (($data['scope'] ?? null) !== $scope || ! array_key_exists('after', $data) || $data['after'] === null) {
            Input::fail('after', 'Directory cursor belongs to another query, actor or contract.');
        }

        return $data['after'];
    }
}
