<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use Closure;
use InvalidArgumentException;
use JsonSerializable;
use LogicException;
use stdClass;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Support\Input;

/**
 * An immutable declaration. It never executes SQL or serializes result rows.
 *
 * @phpstan-type Ast array{table: string, alias?: string, where?: array<string, mixed>, related?: list<array<string, mixed>>, orderBy?: list<array{string, string}>, limit?: int, start?: array{row: stdClass, exclusive: bool}}
 */
final class Query implements JsonSerializable
{
    /** @var Ast */
    private array $ast;

    private ?Condition $condition = null;

    private bool $singular = false;

    private ?int $pageSize = null;

    private bool $paginated = false;

    /** @var array<string, mixed> */
    private array $relationships = [];

    public function __construct(
        public readonly ModelMetadata $model,
        private readonly ModelRegistry $registry,
        private readonly ?string $junction = null,
    ) {
        $this->ast = ['table' => $model->table];
    }

    /** @param string|Closure(ExpressionBuilder): Condition $field */
    public function where(string|Closure $field, mixed $operator = MissingValue::Missing, mixed $value = MissingValue::Missing): self
    {
        $builder = $this->expressionBuilder();
        $condition = $field instanceof Closure ? $field($builder) : (func_num_args() === 2 ? $builder->cmp($field, $operator) : $builder->cmp($field, $operator, $value));
        $query = clone $this;
        $query->condition = ExpressionBuilder::simplify($this->condition instanceof Condition ? $builder->and($this->condition, $condition) : $condition);
        $query->ast['where'] = $query->condition->toArray();

        return $query;
    }

    /** @param (Closure(Query): Query)|array<string, bool>|null $refine
     * @param  array<string, bool>  $options
     */
    public function whereExists(string $relationship, Closure|array|null $refine = null, array $options = []): self
    {
        if (is_array($refine)) {
            $options = $refine;
            $refine = null;
        }

        return $this->where(fn (ExpressionBuilder $expression): Condition => $expression->exists($relationship, $refine, $options));
    }

    /** @param Closure(Query): Query|null $refine */
    public function related(string $relationship, ?Closure $refine = null): self
    {
        [$related, $child] = $this->relationship($relationship, $refine, false);
        $query = clone $this;
        $query->ast['related'][] = $related;
        $query->relationships[$relationship] = $child->format();

        return $query;
    }

    public function orderBy(string $field, string $direction): self
    {
        if (! isset($this->model->columns[$field]) || ! in_array($direction, ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('Ordering requires an exposed column and asc/desc.');
        }

        $this->assertNotJunction('Ordering');
        $query = clone $this;
        $query->ast['orderBy'][] = [$field, $direction];

        return $query;
    }

    public function limit(int $limit): self
    {
        if ($limit < 0 || $limit > 2147483647) {
            throw new InvalidArgumentException('Native limits must be non-negative signed 32-bit integers.');
        }

        $this->assertNotJunction('Limit');
        $query = clone $this;
        $query->ast['limit'] = $limit;

        return $query;
    }

    /** @param array<string, mixed> $row
     * @param  array{inclusive?: bool}  $options
     */
    public function start(array $row, array $options = []): self
    {
        Input::keys($row, array_keys($this->model->columns), 'cursor');
        Input::keys($options, ['inclusive'], 'cursor.options');
        foreach ($row as $field => $value) {
            Input::value($value, $this->model->columns[$field], 'cursor.'.$field);
        }

        if (isset($options['inclusive']) && ! is_bool($options['inclusive'])) {
            throw new InvalidArgumentException('Cursor inclusive must be boolean.');
        }

        $query = clone $this;
        $query->ast['start'] = ['row' => (object) self::copyValues($row), 'exclusive' => ! ($options['inclusive'] ?? false)];

        return $query;
    }

    public function one(): self
    {
        $query = clone $this;
        $query->ast['limit'] = 1;
        $query->singular = true;

        return $query;
    }

    /** Collection cardinality is already the default; no hidden bounds. */
    public function all(): self
    {
        return $this;
    }

    /** A live growing window with one additional row as the exhaustion probe. */
    public function infinite(int $pageSize): self
    {
        if ($this->singular || $pageSize < 1 || $pageSize >= 2147483647 || isset($this->ast['limit']) || isset($this->ast['start'])) {
            throw new InvalidArgumentException('Infinite queries require a collection and a positive page size.');
        }

        $query = $this;
        if (! in_array($this->model->key, array_column($this->ast['orderBy'] ?? [], 0), true)) {
            $query = $query->orderBy($this->model->key, 'asc');
        }

        $query = $query->limit($pageSize + 1);
        $query->pageSize = $pageSize;

        return $query;
    }

    public function pageSize(): ?int
    {
        return $this->pageSize;
    }

    /** Live cursor windows, not numbered snapshots or a total-count paginator. */
    public function paginate(int $pageSize): self
    {
        $query = $this->infinite($pageSize);
        $query->paginated = true;

        return $query;
    }

    public function isPaginated(): bool
    {
        return $this->paginated;
    }

    /** @return list<string> */
    public function cursorFields(): array
    {
        return array_values(array_unique(array_column($this->ast['orderBy'] ?? [], 0)));
    }

    public function expressionBuilder(): ExpressionBuilder
    {
        return new ExpressionBuilder($this->model, function (string $name, ?Closure $refine, array $options): Condition {
            Input::keys($options, ['flip', 'scalar'], 'exists.options');
            foreach ($options as $value) {
                if (! is_bool($value)) {
                    throw new InvalidArgumentException('Exists options must be boolean.');
                }
            }

            [$related] = $this->relationship($name, $refine, true, $options);

            return new Condition('correlatedSubquery', [
                'related' => $related, 'op' => 'EXISTS',
                ...(isset($options['flip']) ? ['flip' => $options['flip']] : []),
                ...(isset($options['scalar']) && count($this->model->relations[$name]['path']) === 1 ? ['scalar' => $options['scalar']] : []),
            ]);
        });
    }

    /** Raw native construction AST, before server visibility and native normalization.
     * @return array<string, mixed>
     */
    public function ast(): array
    {
        return self::copyValues($this->ast);
    }

    /** @return array{singular: bool, relationships: stdClass} */
    public function format(): array
    {
        return ['singular' => $this->singular, 'relationships' => (object) self::copyValues($this->relationships)];
    }

    public function jsonSerialize(): never
    {
        throw new LogicException('Zero query declarations must be extracted before page serialization.');
    }

    /**
     * Own JSON objects as well as arrays, including nested relationship cursors.
     *
     * @template K of array-key
     *
     * @param  array<K, mixed>  $values
     * @return array<K, mixed>
     */
    private static function copyValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $values[$key] = self::copyValues($value);
            } elseif ($value instanceof stdClass) {
                $values[$key] = (object) self::copyValues((array) $value);
            }
        }

        return $values;
    }

    /** @param Closure(Query): Query|null $refine
     * @param  array<string, bool>  $options
     * @return array{array<string, mixed>, self}
     */
    private function relationship(string $name, ?Closure $refine, bool $predicate, array $options = []): array
    {
        $relation = $this->model->relations[$name] ?? throw new InvalidArgumentException("Unknown relationship {$this->model->name}.{$name}.");
        if (str_starts_with($name, 'zsubq_')) {
            throw new InvalidArgumentException('The zsubq_ relationship prefix is reserved.');
        }

        $path = $relation['path'];
        $junction = count($path) === 2;
        if ($junction && ($options['scalar'] ?? false)) {
            throw new InvalidArgumentException('Scalar exists is only supported for one-hop relationships.');
        }

        $target = $this->registry->get($relation['model']);
        $child = new self($target, $this->registry, $junction ? $name : null);
        $child->singular = ! $predicate && $relation['cardinality'] === 'one';

        $alias = $predicate ? 'zsubq_'.($junction ? 'zhidden_' : '').$name : $name;
        $child->ast['alias'] = $alias;
        $child = $refine instanceof Closure ? $refine($child) : $child;
        if ($child->model !== $target || ($child->ast['alias'] ?? null) !== $alias) {
            throw new LogicException('Relationship refinements must return the provided query or a derivative.');
        }

        $ast = $child->ast;
        if ($junction) {
            $inner = ['system' => 'client', 'correlation' => ['parentField' => $path[1]['source'], 'childField' => $path[1]['dest']], 'subquery' => $ast];
            $ast = ['table' => $path[0]['table'], 'alias' => $predicate ? 'zsubq_'.$name : $name];
            if ($predicate) {
                $ast['where'] = ['type' => 'correlatedSubquery', 'related' => $inner, 'op' => 'EXISTS', ...$options];
            } else {
                $ast['related'] = [$inner];
            }
        }

        return [[
            'system' => 'client',
            'correlation' => ['parentField' => $path[0]['source'], 'childField' => $path[0]['dest']],
            ...($junction && ! $predicate ? ['hidden' => true] : []),
            'subquery' => $ast,
        ], $child];
    }

    private function assertNotJunction(string $operation): void
    {
        if ($this->junction !== null) {
            throw new LogicException("{$operation} is not supported in native junction relationships: {$this->junction}.");
        }
    }
}
