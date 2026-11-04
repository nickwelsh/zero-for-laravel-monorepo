<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use LogicException;
use ZeroForLaravel\Generation\TableCompiler;

/** Explicit application aliases; no model or class name comes from transport input. */
final readonly class TableRegistry
{
    public function __construct(private TableCompiler $compiler) {}

    /** @return list<TableDefinition> */
    public function all(): array
    {
        $tables = [];
        foreach (config()->array('zero.tables') as $name => $class) {
            if (! is_string($name) || ! is_string($class) || ! class_exists($class)) {
                throw new LogicException('zero.tables must map aliases to definition classes.');
            }

            $tables[] = $this->compiler->compile($name, $class);
        }

        return $tables;
    }

    public function find(string $name, string $contract): TableDefinition
    {
        $class = config()->array('zero.tables')[$name] ?? null;
        abort_unless(is_string($class) && class_exists($class), 404);
        $table = $this->compiler->compile($name, $class);
        abort_unless(hash_equals($table->contract, $contract), 409, 'Table contract changed. Reload the application.');

        return $table;
    }
}
