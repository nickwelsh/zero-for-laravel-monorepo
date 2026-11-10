<?php

declare(strict_types=1);

namespace ZeroForLaravel\Tables;

use Illuminate\Support\Str;
use LogicException;
use ReflectionClass;
use ReflectionNamedType;
use ZeroForLaravel\Generation\TableCompiler;
use ZeroForLaravel\Support\ApplicationClasses;

/** Resolve discovered definitions by a URL-safe name, never a client-supplied class. */
final readonly class TableRegistry
{
    public function __construct(private TableCompiler $compiler, private ApplicationClasses $classes) {}

    /** @return list<TableDefinition> */
    public function all(): array
    {
        $tables = [];
        foreach ($this->definitions() as $name => $class) {
            $tables[] = $this->compiler->compile($name, $class);
        }

        return $tables;
    }

    public function find(string $name, string $contract): TableDefinition
    {
        $class = $this->definitions()[$name] ?? null;
        abort_if($class === null, 404);
        $table = $this->compiler->compile($name, $class);
        abort_unless(hash_equals($table->contract, $contract), 409, 'Table contract changed. Reload the application.');

        return $table;
    }

    /** @return array<string, class-string> */
    private function definitions(): array
    {
        $definitions = [];
        foreach ($this->classes->all() as $class) {
            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! str_ends_with($reflection->getShortName(), 'Table') || ! $reflection->hasMethod('definition')) {
                continue;
            }

            $method = $reflection->getMethod('definition');
            $type = $method->getReturnType();
            if (! $method->isPublic() || $method->isStatic() || ! $type instanceof ReflectionNamedType || $type->getName() !== Table::class) {
                continue;
            }

            $name = Str::kebab(substr($reflection->getShortName(), 0, -5));
            if (isset($definitions[$name])) {
                throw new LogicException("Duplicate Zero table name: {$name} ({$definitions[$name]}, {$class}).");
            }

            $definitions[$name] = $class;
        }

        ksort($definitions);

        return $definitions;
    }
}
