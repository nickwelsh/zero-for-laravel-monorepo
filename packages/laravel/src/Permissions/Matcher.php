<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use LogicException;

/** A closed Casbin expression grammar; no PHP evaluation or textual SQL translation. */
final readonly class Matcher
{
    /** @param list<self> $children */
    public function __construct(public string $kind, public string|int|bool|null $value = null, public array $children = []) {}

    public static function parse(string $source): self
    {
        $tokens = [];
        $offset = 0;
        while ($offset < strlen($source)) {
            if (! preg_match('/\G\s*(\&\&|\|\||==|!=|<=|>=|[!<>(),]|"(?:[^"\\\\]|\\\\.)*"|-?\d+|[a-zA-Z_][a-zA-Z0-9_.]*)/A', $source, $match, offset: $offset)) {
                if (trim(substr($source, $offset)) === '') {
                    break;
                }

                throw new LogicException('Unsupported Casbin matcher near '.substr($source, $offset, 80));
            }

            $tokens[] = $match[1];
            $offset += strlen($match[0]);
            if (count($tokens) > 512) {
                throw new LogicException('Portable Casbin matchers are limited to 512 tokens.');
            }
        }

        $position = 0;
        $expression = self::expression($tokens, $position);
        if ($position !== count($tokens)) {
            throw new LogicException('Unexpected Casbin matcher token '.($tokens[$position] ?? 'end'));
        }

        return $expression;
    }

    /** @return list<list<self>> */
    public function branches(bool $negated = false): array
    {
        if ($this->kind === 'not') {
            return $this->children[0]->branches(! $negated);
        }

        if ($this->kind === '&&' || $this->kind === '||') {
            $conjunction = ($this->kind === '&&') !== $negated;
            $left = $this->children[0]->branches($negated);
            $right = $this->children[1]->branches($negated);
            if (! $conjunction) {
                $result = [...$left, ...$right];
            } else {
                $result = [];
                foreach ($left as $a) {
                    foreach ($right as $b) {
                        $result[] = [...$a, ...$b];
                    }
                }
            }

            if (count($result) > 64) {
                throw new LogicException('Portable Casbin matcher exceeds 64 predicate branches.');
            }

            return $result;
        }

        if ($this->kind === 'role') {
            if ($negated) {
                throw new LogicException('Negated Casbin role reachability is not portable.');
            }

            return [[$this]];
        }

        $operator = $this->kind;
        if ($negated) {
            $operator = match ($operator) {
                '==' => '!=', '!=' => '==', '<' => '>=', '<=' => '>', '>' => '<=', '>=' => '<',
                default => throw new LogicException('Portable matchers require typed comparisons.'),
            };
        }

        if (! in_array($operator, ['==', '!=', '<', '<=', '>', '>='], true)) {
            throw new LogicException('Portable matchers require typed comparisons.');
        }

        return [[new self($operator, children: $this->children)]];
    }

    public function field(): string
    {
        if ($this->kind !== 'field' || ! is_string($this->value)) {
            throw new LogicException('Expected a Casbin field reference.');
        }

        return $this->value;
    }

    /** PHP-Casbin uses loose string equality by default; portable models require strict comparisons. */
    public function strictExpression(): string
    {
        if ($this->kind === 'field') {
            $field = $this->field();

            // Escape variable names here, never inside literal strings.
            return $field[0].'_'.substr($field, 2);
        }

        if ($this->kind === 'literal') {
            return json_encode($this->value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $children = array_map(fn (self $child): string => $child->strictExpression(), $this->children);
        if ($this->kind === 'role') {
            return 'g('.implode(', ', $children).')';
        }

        if ($this->kind === 'not') {
            return '!('.$children[0].')';
        }

        $operator = match ($this->kind) {
            '==' => '===', '!=' => '!==', default => $this->kind
        };

        return '('.$children[0].' '.$operator.' '.$children[1].')';
    }

    /** @param list<string> $tokens */
    private static function expression(array $tokens, int &$position, int $minimum = 0): self
    {
        $token = $tokens[$position++] ?? throw new LogicException('Incomplete Casbin matcher.');
        if ($token === '!') {
            $left = new self('not', children: [self::expression($tokens, $position, 4)]);
        } elseif ($token === '(') {
            $left = self::expression($tokens, $position);
            self::expect($tokens, $position, ')');
        } elseif ($token === 'g') {
            self::expect($tokens, $position, '(');
            $arguments = [self::expression($tokens, $position)];
            while (($tokens[$position] ?? null) === ',') {
                $position++;
                $arguments[] = self::expression($tokens, $position);
            }

            self::expect($tokens, $position, ')');
            $left = new self('role', children: $arguments);
        } elseif ($token === 'true' || $token === 'false') {
            $left = new self('literal', $token === 'true');
        } elseif (str_starts_with($token, '"')) {
            $value = json_decode($token, flags: JSON_THROW_ON_ERROR);
            if (! is_string($value)) {
                throw new LogicException('Invalid Casbin string literal.');
            }

            $left = new self('literal', $value);
        } elseif (preg_match('/^-?\d+$/D', $token)) {
            $value = filter_var($token, FILTER_VALIDATE_INT);
            if ($value === false || abs($value) > 9007199254740991) {
                throw new LogicException('Casbin integer literals must be JavaScript-safe integers.');
            }

            $left = new self('literal', $value);
        } elseif (preg_match('/^(r\.(sub\.(key|id|[a-zA-Z_][a-zA-Z0-9_]*)|obj\.(type|id|[a-zA-Z_][a-zA-Z0-9_]*)|dom|act)|p\.[a-z]+)$/D', $token)) {
            $left = new self('field', $token);
        } else {
            throw new LogicException("Unsupported Casbin matcher token {$token}; custom functions, eval and clock expressions are server-only.");
        }

        $precedence = ['||' => 1, '&&' => 2, '==' => 3, '!=' => 3, '<' => 3, '<=' => 3, '>' => 3, '>=' => 3];
        while (isset($tokens[$position]) && ($precedence[$tokens[$position]] ?? -1) >= $minimum) {
            $operator = $tokens[$position++];
            $right = self::expression($tokens, $position, $precedence[$operator] + 1);
            $left = new self($operator, children: [$left, $right]);
        }

        return $left;
    }

    /** @param list<string> $tokens */
    private static function expect(array $tokens, int &$position, string $expected): void
    {
        if (($tokens[$position++] ?? null) !== $expected) {
            throw new LogicException("Expected {$expected} in Casbin matcher.");
        }
    }
}
