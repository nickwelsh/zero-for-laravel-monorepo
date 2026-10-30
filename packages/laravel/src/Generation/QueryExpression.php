<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use ZeroForLaravel\Metadata\ModelMetadata;

/** Checked, portable query construction. Both emitters consume this same tree. */
final readonly class QueryExpression
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

    public function emit(bool $typescript): string
    {
        $child = fn (int $index): string => $this->children[$index]->emit($typescript);
        $literal = $typescript ? json_encode($this->value, JSON_THROW_ON_ERROR) : var_export($this->value, true);

        return match ($this->kind) {
            'literal' => $literal,
            'argument', 'enum', 'validated' => $typescript ? 'args['.$literal.']' : '$args['.$literal.']',
            'field' => $child(0).'['.$literal.']',
            'dateOperand' => $typescript
                ? ($this->value === null ? 'new Date('.$child(0).').getTime()' : 'encodeDate(new Date('.$child(0).'), '.$literal.')')
                : \Carbon\CarbonImmutable::class.'::parse('.$child(0).', "UTC")->utc()->'.($this->value === null ? 'getTimestampMs()' : 'format('.$literal.')'),
            'isset' => $typescript ? '('.$child(0).' !== undefined && '.$child(0).' !== null)' : 'isset('.$child(0).')',
            'has' => $typescript ? 'Object.hasOwn('.$child(1).', '.$child(0).')' : 'array_key_exists('.$child(0).', '.$child(1).')',
            'local' => ($typescript ? '' : '$').$this->value,
            'callback' => ($typescript ? '('.$this->value.') => ' : 'static fn ($'.$this->value.') => ').$child(0),
            'list' => '['.implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), $this->children)).']',
            'object' => ($typescript ? '{' : '[').implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), $this->children)).($typescript ? '}' : ']'),
            'property' => $literal.($typescript ? ': ' : ' => ').$child(0),
            'query' => $typescript ? 'zql['.json_encode($this->model?->table, JSON_THROW_ON_ERROR).']' : '\\'.$this->model?->class.'::zeroQuery()',
            'bound' => ($typescript ? 'zql['.json_encode($this->model?->table, JSON_THROW_ON_ERROR).'].where(' : '\\'.$this->model?->class.'::zeroQuery()->where(').json_encode($this->model?->key, JSON_THROW_ON_ERROR).', '.($typescript ? 'args[' : '$args[').$literal.']'.($typescript ? ').one()' : ')->one()'),
            'infinite' => $typescript ? $child(0).'.limit(args.__window ?? '.((int) $this->value + 1).')' : $child(0).'->infinite('.$literal.')',
            'paginate' => $typescript ? '(args.__cursor === undefined ? '.$child(0).'.limit('.((int) $this->value + 1).') : '.$child(0).'.limit('.((int) $this->value + 1).').start(args.__cursor))' : $child(0).'->paginate('.$literal.')',
            'method' => $this->value === 'all' && $typescript ? $child(0) : $child(0).($typescript ? '.' : '->').$this->value.'('.implode(', ', array_map(fn (self $arg): string => $arg->emit($typescript), array_slice($this->children, 1))).')',
            'conditional' => '('.$child(0).' ? '.$child(1).' : '.$child(2).')',
            'binary' => '('.$child(0).' '.($typescript && $this->value === '.' ? '+' : $this->value).' '.$child(1).')',
            'not' => '(!'.$child(0).')',
            default => throw new LogicException('Unsupported query expression '.$this->kind),
        };
    }

    public function ordersKey(): bool
    {
        if ($this->kind === 'conditional') {
            return $this->children[1]->ordersKey() && $this->children[2]->ordersKey();
        }

        return $this->kind === 'method' && (($this->value === 'orderBy' && $this->children[1]->value === $this->model?->key) || $this->children[0]->ordersKey());
    }

    /** @return list<string> */
    public function cursorFields(): array
    {
        if ($this->kind === 'conditional') {
            if ($this->children[1]->cursorFields() !== $this->children[2]->cursorFields()) {
                throw new LogicException('Cursor pagination requires the same ordering fields on every branch.');
            }

            return $this->children[1]->cursorFields();
        }

        if (! in_array($this->kind, ['method', 'paginate', 'infinite'], true)) {
            return [];
        }

        $fields = $this->children[0]->cursorFields();
        if ($this->value === 'orderBy' && is_string($this->children[1]->value)) {
            $fields[] = $this->children[1]->value;
        }

        return array_values(array_unique($fields));
    }

    public function hasBounds(): bool
    {
        if ($this->kind === 'conditional') {
            return $this->children[1]->hasBounds() || $this->children[2]->hasBounds();
        }

        return in_array($this->kind, ['paginate', 'infinite'], true)
            || ($this->kind === 'method' && (in_array($this->value, ['limit', 'start', 'one'], true) || $this->children[0]->hasBounds()));
    }
}
