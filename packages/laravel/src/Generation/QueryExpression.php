<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Carbon\CarbonImmutable;
use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;

/** Checked, portable query construction. Both emitters consume this same tree. */
final class QueryExpression
{
    /** @param list<self> $children */
    public function __construct(
        public string $kind,
        public string|int|float|bool|null $value = null,
        public array $children = [],
        public ?ModelMetadata $model = null,
        public string $type = 'unknown',
        public bool $singular = false,
    ) {}

    public static function reference(self $query): self
    {
        return new self('reference', children: [$query], model: $query->model, type: $query->type, singular: $query->singular);
    }

    public function snapshot(): self
    {
        if ($this->kind === 'reference') {
            return $this->children[0]->snapshot();
        }

        return new self($this->kind, $this->value, array_map(fn (self $child): self => $child->snapshot(), $this->children), $this->model, $this->type, $this->singular);
    }

    public function queryModel(): ModelMetadata
    {
        return $this->model ?? throw new LogicException('A query expression requires model metadata.');
    }

    public function stringValue(): string
    {
        return is_string($this->value) ? $this->value : throw new LogicException('Expected a literal name or context field.');
    }

    /** Walk a dotted include one relationship at a time when inspecting a scope outside a model read. */
    public function firstRelationHop(): self
    {
        $path = $this->children[1]->stringValue();
        if ($this->value !== 'with' || ! str_contains($path, '.')) {
            return $this;
        }

        [$head, $tail] = explode('.', $path, 2);
        $model = $this->queryModel();
        $target = $model->registry->get($model->registry->relation($model, $head)['model']);
        if (! $target->returnsRelation(explode('.', $tail, 2)[0])) {
            return new self('method', 'with', [$this->children[0], ScopeCompiler::value($head)], $model, 'query', $this->singular);
        }

        $local = new self('local', 'zeroRelated', model: $target, type: 'query');
        $body = new self('method', 'with', [$local, ScopeCompiler::value($tail), ...array_slice($this->children, 2)], $target, 'query');

        return new self('method', 'with', [$this->children[0], ScopeCompiler::value($head), new self('callback', 'zeroRelated', [$body], type: 'callback')], $model, 'query', $this->singular);
    }

    public function emit(bool $typescript): string
    {
        if ($this->kind === 'reference') {
            return $this->snapshot()->emit($typescript);
        }

        if ($typescript && $this->type === 'query') {
            return (new NativeQueryEmitter)->emit($this);
        }

        $child = fn (int $index): string => $this->children[$index]->emit($typescript);
        $literal = $typescript ? json_encode($this->value, JSON_THROW_ON_ERROR) : var_export($this->value, true);

        return match ($this->kind) {
            'literal' => $literal,
            'actor' => $typescript ? 'undefined' : 'request()->user()',
            'guarded' => $typescript ? $child(0) : $child(0).'->guardedBy(request()->user(), '.$literal.')',
            'context' => $typescript ? 'ctx['.$literal.']' : 'new \\ZeroForLaravel\\Context\\ContextReference('.$literal.')',
            'argument', 'enum', 'validated' => $typescript ? 'args['.$literal.']' : '$args['.$literal.']',
            'optionContains' => ($typescript ? 'optionContains' : \ZeroForLaravel\Formbraid\OptionPattern::class.'::contains').'('.$child(0).')',
            'field' => $child(0).'['.$literal.']',
            'dateOperand' => $typescript
                ? ($this->value === null ? 'new Date('.$child(0).').getTime()' : 'encodeDate(new Date('.$child(0).'), '.$literal.')')
                : CarbonImmutable::class.'::parse('.$child(0).', "UTC")->utc()->'.($this->value === null ? 'getTimestampMs()' : 'format('.$literal.')'),
            'isset' => $typescript ? '('.$child(0).' !== undefined && '.$child(0).' !== null)' : 'isset('.$child(0).')',
            'has' => $typescript ? 'Object.hasOwn('.$child(1).', '.$child(0).')' : 'array_key_exists('.$child(0).', '.$child(1).')',
            'local' => ($typescript ? '' : '$').$this->value,
            'callback' => ($typescript ? '('.$this->value.') => ' : 'static fn ($'.$this->value.') => ').$child(0),
            'scope' => $child(0).'->'.$this->value.'('.implode(', ', array_map(fn (self $arg): string => $arg->emit(false), array_slice($this->children, 2))).')',
            'list' => '['.implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), $this->children)).']',
            'object' => ($typescript ? '{' : '[').implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), $this->children)).($typescript ? '}' : ']'),
            'property' => $literal.($typescript ? ': ' : ' => ').$child(0),
            'query' => $typescript ? 'zql['.json_encode($this->model?->table, JSON_THROW_ON_ERROR).']' : '\\'.$this->model?->class.'::zeroQuery()',
            'bound' => ($typescript ? 'zql['.json_encode($this->model?->table, JSON_THROW_ON_ERROR).'].where(' : '\\'.$this->model?->class.'::zeroQuery()->where(').json_encode($this->model?->key, JSON_THROW_ON_ERROR).', '.($typescript ? 'args[' : '$args[').$literal.']'.($typescript ? ').one()' : ')->first()'),
            'paginate' => match ($this->value) {
                'cursor' => $child(0).'->cursorPaginate('.$child(1).', cursorName: '.$child(2).')',
                'scroll' => \ZeroForLaravel\Zero::class.'::scroll('.$child(0).'->paginate('.$child(1).', pageName: '.$child(2).'))',
                default => $child(0).'->paginate('.$child(1).', pageName: '.$child(2).')',
            },
            'method' => in_array($this->value, ['all', 'visibleTo'], true) && $typescript ? $child(0) : $child(0).($typescript ? '.' : '->').$this->value.'('.implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), array_slice($this->children, 1))).')',
            'conditional' => '('.$child(0).' ? '.$child(1).' : '.$child(2).')',
            'binary' => '('.$child(0).' '.($typescript && $this->value === '.' ? '+' : $this->value).' '.$child(1).')',
            'not' => '(!'.$child(0).')',
            default => throw new LogicException('Unsupported query expression '.$this->kind),
        };
    }

    public function isTerminal(): bool
    {
        return match ($this->kind) {
            'reference' => $this->children[0]->isTerminal(),
            'conditional' => $this->children[1]->isTerminal() && $this->children[2]->isTerminal(),
            'method' => in_array($this->value, ['first', 'get'], true),
            'bound', 'guarded', 'paginate' => true,
            default => false,
        };
    }

    public function ordersKey(): bool
    {
        if ($this->kind === 'paginate') {
            return true;
        }

        if ($this->kind === 'reference') {
            return $this->children[0]->ordersKey();
        }

        if ($this->kind === 'conditional') {
            return $this->children[1]->ordersKey() && $this->children[2]->ordersKey();
        }

        return $this->kind === 'method' && (($this->value === 'orderBy' && $this->children[1]->value === $this->model?->key) || $this->children[0]->ordersKey());
    }

    /** Push trailing operations into each branch so scopes and orders apply to that branch alone. */
    public function distribute(): self
    {
        if (in_array($this->kind, ['method', 'scope', 'paginate', 'guarded'], true)) {
            $receiver = $this->children[0]->distribute();
            if ($receiver->kind === 'conditional') {
                $yes = clone $this;
                $no = clone $this;
                $yes->children[0] = $receiver->children[1];
                $no->children[0] = $receiver->children[2];

                return new self('conditional', children: [$receiver->children[0], $yes->distribute(), $no->distribute()], model: $this->model, type: 'query', singular: $this->singular);
            }
        }

        return $this;
    }

    /** @return list<string> */
    public function cursorFields(): array
    {
        if ($this->kind === 'reference') {
            return $this->children[0]->cursorFields();
        }

        if ($this->kind === 'paginate' && ($branches = $this->distribute())->kind === 'conditional') {
            return $branches->cursorFields();
        }

        if ($this->kind === 'conditional') {
            $yes = $this->children[1]->cursorFields();
            $no = $this->children[2]->cursorFields();
            if (array_diff($yes, $no) !== [] || array_diff($no, $yes) !== []) {
                throw new LogicException('Cursor pagination requires the same ordering fields on every branch.');
            }

            return $yes;
        }

        if (! in_array($this->kind, ['method', 'scope', 'paginate'], true)) {
            return [];
        }

        $fields = $this->children[0]->cursorFields();
        if ($this->kind === 'scope') {
            array_push($fields, ...$this->children[1]->cursorFields());
        } elseif ($this->kind === 'paginate') {
            $removed = ScopeCompiler::removals($this);
            if ($removed !== null) {
                foreach (ScopeCompiler::globals($this->queryModel()) as $name => $scope) {
                    if (! in_array($name, $removed, true)) {
                        array_push($fields, ...$scope->cursorFields());
                    }
                }
            }

            $fields[] = $this->queryModel()->key;
        } elseif ($this->value === 'orderBy' && is_string($this->children[1]->value)) {
            $fields[] = $this->children[1]->value;
        }

        return array_values(array_unique($fields));
    }

    public function hasBounds(): bool
    {
        if ($this->kind === 'reference') {
            return $this->children[0]->hasBounds();
        }

        if ($this->kind === 'conditional') {
            return $this->children[1]->hasBounds() || $this->children[2]->hasBounds();
        }

        return $this->kind === 'paginate'
            || ($this->kind === 'scope' && ($this->children[0]->hasBounds() || $this->children[1]->hasBounds()))
            || ($this->kind === 'method' && (in_array($this->value, ['limit', 'start', 'first'], true) || $this->children[0]->hasBounds()));
    }
}
