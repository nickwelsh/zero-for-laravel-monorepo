<?php

declare(strict_types=1);

namespace ZeroForLaravel\Search;

use Illuminate\Validation\ValidationException;

/** Validate the expression grammar before combining it with a mandatory server scope. */
final class TypesenseFilter
{
    private int $offset = 0;

    private int $remaining = 512;

    private string $input = '';

    /** @var array<string, string> */
    private array $fields = [];

    /** @param array<string, string> $fields */
    public function validate(string $input, array $fields): string
    {
        $this->input = $input;
        $this->fields = $fields;
        $this->offset = 0;
        $this->remaining = 512;
        if (trim($input) === '') {
            return '';
        }

        $this->expression(0);
        $this->space();
        if ($this->offset !== strlen($this->input)) {
            $this->fail();
        }

        return $input;
    }

    private function expression(int $depth): void
    {
        if ($depth > 16 || --$this->remaining < 0) {
            $this->fail();
        }

        $this->primary($depth);
        while (true) {
            $this->space();
            if (! $this->take('&&') && ! $this->take('||')) {
                return;
            }

            $this->primary($depth);
        }
    }

    private function primary(int $depth): void
    {
        $this->space();
        if ($this->take('(')) {
            $this->expression($depth + 1);
            $this->space();
            if (! $this->take(')')) {
                $this->fail();
            }

            return;
        }

        if (--$this->remaining < 0 || ! preg_match('/\G([a-zA-Z_][a-zA-Z0-9_]*)\s*:(>=|<=|!=|>|<|=|!)?/A', $this->input, $match, 0, $this->offset)) {
            $this->fail();
        }

        if (! array_key_exists($match[1], $this->fields)) {
            $this->fail();
        }

        $this->offset += strlen($match[0]);
        $this->space();
        if ($this->take('[')) {
            $count = 0;
            do {
                if (++$count > 100) {
                    $this->fail();
                }

                $this->value();
                $this->space();
            } while ($this->take(','));

            if (! $this->take(']')) {
                $this->fail();
            }
        } else {
            $this->value();
        }
    }

    private function value(): void
    {
        $this->space();
        if ($this->take('`')) {
            $length = strlen($this->input);
            while ($this->offset < $length) {
                $character = $this->input[$this->offset++];
                if ($character === '`') {
                    return;
                }

                if ($character === '\\') {
                    if ($this->offset >= $length) {
                        $this->fail();
                    }

                    $this->offset++;
                } elseif (ord($character) < 32) {
                    $this->fail();
                }
            }

            $this->fail();
        }

        if (! preg_match('/\G[-a-zA-Z0-9_.]+/A', $this->input, $match, 0, $this->offset)) {
            $this->fail();
        }

        $this->offset += strlen($match[0]);
    }

    private function take(string $token): bool
    {
        if (substr($this->input, $this->offset, strlen($token)) !== $token) {
            return false;
        }

        $this->offset += strlen($token);

        return true;
    }

    private function space(): void
    {
        while (isset($this->input[$this->offset]) && ctype_space($this->input[$this->offset])) {
            $this->offset++;
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['filter_by' => 'Invalid or unapproved search filter.']);
    }
}
