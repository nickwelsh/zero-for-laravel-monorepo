<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use LogicException;

/** Typed access to compiler-owned serialized nodes; never consumes browser ASTs. */
final readonly class OperationNode
{
    /** @param array<string, mixed> $data */
    public function __construct(public array $data) {}

    public static function from(mixed $data): self
    {
        if (! is_array($data)) {
            throw new LogicException('Compiled operation must be an object.');
        }

        $object = [];
        foreach ($data as $key => $value) {
            if (! is_string($key)) {
                throw new LogicException('Invalid compiled operation key.');
            }

            $object[$key] = $value;
        }

        return new self($object);
    }

    public function value(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function string(string $key): string
    {
        $value = $this->value($key);
        if (! is_string($value)) {
            throw new LogicException('Compiled operation requires string '.$key);
        }

        return $value;
    }

    public function integer(string $key): int
    {
        $value = $this->value($key);
        if (! is_int($value)) {
            throw new LogicException('Compiled operation requires integer '.$key);
        }

        return $value;
    }

    public function node(string $key): self
    {
        return self::from($this->value($key));
    }

    /** @return list<self> */
    public function nodes(string $key): array
    {
        $value = $this->value($key);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Compiled operation requires node list '.$key);
        }

        return array_map(self::from(...), $value);
    }

    /** @return list<string> */
    public function strings(string $key): array
    {
        $value = $this->value($key);
        if (! is_array($value) || ! array_is_list($value)) {
            throw new LogicException('Compiled operation requires string list '.$key);
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new LogicException('Invalid compiled string list.');
            }
        }

        return $value;
    }
}
