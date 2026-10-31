<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use DateTimeInterface;
use LogicException;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use stdClass;
use ZeroForLaravel\Domain\OperationException;

/** Validator-independent public JSON shape, including omission versus null. */
final readonly class ValueContract
{
    /**
     * @param  array<string, self>  $fields
     * @param  list<self>  $members
     * @param  list<string>  $rules  Laravel scalar validation constraints
     */
    public function __construct(
        public string $kind,
        public array $fields = [],
        public array $members = [],
        public bool $optional = false,
        public bool $nullable = false,
        public array $rules = [],
    ) {}

    public static function parse(string $source): self
    {
        $config = new ParserConfig([]);
        $tokens = new TokenIterator((new Lexer($config))->tokenize($source));
        $node = (new TypeParser($config, new ConstExprParser($config)))->parse($tokens);
        $tokens->consumeTokenType(Lexer::TOKEN_END);

        return self::fromType($node);
    }

    public function includesVoid(): bool
    {
        return $this->kind === 'void' || array_filter($this->members, fn (self $member): bool => $member->includesVoid()) !== [];
    }

    /** @return list<string|int>|null */
    public function choices(): ?array
    {
        $choices = null;
        foreach ($this->rules as $rule) {
            $values = null;
            if (str_starts_with($rule, 'choices:')) {
                $decoded = json_decode(substr($rule, 8), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ! array_is_list($decoded)) {
                    throw new LogicException('Invalid finite input choices.');
                }

                $values = [];
                foreach ($decoded as $value) {
                    if (! is_string($value) && ! is_int($value)) {
                        throw new LogicException('Choices must be strings or integers.');
                    }

                    $values[] = $value;
                }
            } elseif (str_starts_with($rule, 'in:') && in_array($this->kind, ['string', 'int'], true)) {
                $values = [];
                foreach (str_getcsv(substr($rule, 3), escape: '') as $value) {
                    if ($value === null || ($this->kind === 'int' && filter_var($value, FILTER_VALIDATE_INT) === false)) {
                        throw new LogicException('Invalid finite input choice.');
                    }

                    $values[] = $this->kind === 'int' ? (int) $value : $value;
                }
            }

            if ($values !== null) {
                foreach ($values as $value) {
                    if (($this->kind === 'string' && ! is_string($value)) || ($this->kind === 'int' && ! is_int($value))) {
                        throw new LogicException('Finite choices must match the declared input scalar type.');
                    }
                }

                $choices = $choices === null ? $values : array_values(array_filter($choices, fn (string|int $choice): bool => in_array($choice, $values, true)));
            }
        }

        if ($choices === []) {
            throw new LogicException('Finite input choices cannot be empty.');
        }

        return $choices;
    }

    /** Validate and preserve JSON object/list identity before committing a result. */
    public function normalize(mixed $value): mixed
    {
        if ($value === null && $this->nullable) {
            return null;
        }

        if ($this->kind === 'union') {
            foreach ($this->members as $member) {
                try {
                    return $member->normalize($value);
                } catch (OperationException) {
                    // Try the next explicitly declared variant.
                }
            }
        } elseif ($this->kind === 'object' && (is_array($value) || $value instanceof stdClass)) {
            $values = (array) $value;
            if (array_diff(array_keys($values), array_keys($this->fields)) === []) {
                $result = [];
                foreach ($this->fields as $key => $field) {
                    if (! array_key_exists($key, $values)) {
                        if ($field->optional) {
                            continue;
                        }

                        throw new OperationException('invalid_result', 'Missing result field '.$key);
                    }

                    $result[$key] = $field->normalize($values[$key]);
                }

                return $result === [] ? new stdClass : $result;
            }
        } elseif (in_array($this->kind, ['list', 'tuple'], true) && is_array($value) && array_is_list($value)) {
            if ($this->kind === 'list' || count($value) === count($this->members)) {
                return array_map(fn (mixed $item, int $index): mixed => $this->members[$this->kind === 'list' ? 0 : $index]->normalize($item), $value, array_keys($value));
            }
        } elseif (match ($this->kind) {
            'void', 'null' => $value === null,
            'string' => is_string($value), 'bool' => is_bool($value),
            'int' => is_int($value) && abs($value) <= 9007199254740991,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            default => false,
        }) {
            return $value;
        }

        throw new OperationException('invalid_result', 'Mutation result does not match its declared JSON shape.');
    }

    public function php(): string
    {
        $fields = [];
        foreach ($this->fields as $key => $field) {
            $fields[] = var_export($key, true).' => '.$field->php();
        }

        return 'new \\'.self::class.'('.var_export($this->kind, true).', ['.implode(', ', $fields).'], ['.implode(', ', array_map(fn (self $member): string => $member->php(), $this->members)).'], '.var_export($this->optional, true).', '.var_export($this->nullable, true).')';
    }

    public function typescript(): string
    {
        $base = match ($this->kind) {
            'date', 'dateOnly' => 'Date',
            'string', 'dateWire', 'dateOnlyWire' => 'string', 'int', 'number' => 'number', 'bool' => 'boolean',
            'null' => 'null', 'void' => 'undefined',
            'object' => '{'.implode('; ', array_map(fn (string $key, self $field): string => 'readonly '.json_encode($key, JSON_THROW_ON_ERROR).($field->optional ? '?' : '').': '.$field->typescript().($field->optional ? ' | undefined' : ''), array_keys($this->fields), array_values($this->fields))).'}',
            'tuple' => 'readonly ['.implode(', ', array_map(fn (self $item): string => $item->typescript(), $this->members)).']',
            'list' => 'ReadonlyArray<'.$this->members[0]->typescript().'>',
            'union' => implode(' | ', array_map(fn (self $item): string => '('.$item->typescript().')', $this->members)),
            default => throw new LogicException('Unsupported mutation value contract '.$this->kind),
        };

        if (($choices = $this->choices()) !== null) {
            $base = implode(' | ', array_map(fn (string|int $value): string => json_encode($value, JSON_THROW_ON_ERROR), $choices));
        }

        return $base.($this->nullable && $this->kind !== 'null' ? ' | null' : '');
    }

    /** Encode public values before Zero captures the invocation's JSON arguments. */
    public function encode(string $expression): string
    {
        $fields = [];
        foreach ($this->fields as $key => $field) {
            $name = json_encode($key, JSON_THROW_ON_ERROR);
            $access = $expression.'['.$name.']';
            $property = $name.': '.$field->encode($access);
            $fields[] = $field->optional ? '...('.$access.' === undefined ? {} : {'.$property.'})' : $property;
        }

        $encoded = match ($this->kind) {
            'date' => $expression.'.toISOString()',
            'dateOnly' => $expression.'.toISOString().slice(0, 10)',
            'object' => '({'.implode(', ', $fields).'})',
            'list' => $expression.'.map(item => '.$this->members[0]->encode('item').')',
            default => $expression,
        };

        return $this->nullable ? '('.$expression.' === null ? null : '.$encoded.')' : $encoded;
    }

    public function wire(): self
    {
        return new self(match ($this->kind) {
            'date' => 'dateWire', 'dateOnly' => 'dateOnlyWire', default => $this->kind
        },
            fields: array_map(fn (self $field): self => $field->wire(), $this->fields),
            members: array_map(fn (self $field): self => $field->wire(), $this->members),
            optional: $this->optional, nullable: $this->nullable, rules: $this->rules);
    }

    /** Normalize already Laravel-validated page arguments into the canonical wire representation. */
    public function encodeValidated(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (in_array($this->kind, ['date', 'dateOnly'], true)) {
            if (! is_string($value) && ! $value instanceof DateTimeInterface) {
                throw new LogicException('Expected a validated date value.');
            }

            $date = \Carbon\CarbonImmutable::parse($value, 'UTC')->utc();

            return $date->format($this->kind === 'dateOnly' ? 'Y-m-d' : 'Y-m-d\TH:i:s.v\Z');
        }

        if ($this->kind === 'object' && is_array($value)) {
            foreach ($this->fields as $key => $field) {
                if (array_key_exists($key, $value)) {
                    $value[$key] = $field->encodeValidated($value[$key]);
                }
            }
        } elseif ($this->kind === 'list' && is_array($value)) {
            $value = array_map($this->members[0]->encodeValidated(...), $value);
        }

        return $value;
    }

    private static function fromType(Type\TypeNode $node, bool $optional = false): self
    {
        if ($node instanceof Type\IdentifierTypeNode) {
            $kind = match ($node->name) {
                'string' => 'string', 'int', 'integer' => 'int', 'float', 'number' => 'number',
                'bool', 'boolean' => 'bool', 'null' => 'null', 'void' => 'void',
                default => throw new LogicException('Declare a concrete JSON result shape, not '.$node->name),
            };

            return new self($kind, optional: $optional);
        }

        if ($node instanceof Type\NullableTypeNode || $node instanceof Type\UnionTypeNode) {
            $types = $node instanceof Type\NullableTypeNode ? [$node->type, new Type\IdentifierTypeNode('null')] : $node->types;

            return new self('union', members: array_map(self::fromType(...), array_values($types)), optional: $optional);
        }

        if ($node instanceof Type\GenericTypeNode && (string) $node->type === 'list' && count($node->genericTypes) === 1) {
            $item = self::fromType($node->genericTypes[0]);
            if ($item->includesVoid()) {
                throw new LogicException('JSON list items cannot be void.');
            }

            return new self('list', members: [$item], optional: $optional);
        }

        if ($node instanceof Type\ArrayShapeNode && $node->sealed) {
            $fields = [];
            $members = [];
            foreach ($node->items as $item) {
                if ($item->keyName === null && ! $item->optional) {
                    $members[] = self::fromType($item->valueType);
                } elseif ($item->keyName instanceof Type\IdentifierTypeNode || $item->keyName instanceof ConstExprStringNode) {
                    $key = $item->keyName instanceof Type\IdentifierTypeNode ? $item->keyName->name : $item->keyName->value;
                    if (is_numeric($key) || array_key_exists($key, $fields)) {
                        throw new LogicException('Result object keys must be unique nonnumeric strings.');
                    }

                    $fields[$key] = self::fromType($item->valueType, $item->optional);
                } else {
                    throw new LogicException('Use dense tuple or string-keyed result shapes.');
                }
            }

            if ($members !== [] && $fields !== []) {
                throw new LogicException('Mixed list/object result shapes are unsupported.');
            }

            foreach ([...array_values($fields), ...$members] as $field) {
                if ($field->includesVoid()) {
                    throw new LogicException('JSON fields cannot be void; use an optional field or null.');
                }
            }

            return new self($members !== [] || $node->kind === 'list' ? 'tuple' : 'object', fields: $fields, members: $members, optional: $optional);
        }

        throw new LogicException('Unsupported JSON result contract '.$node);
    }
}
