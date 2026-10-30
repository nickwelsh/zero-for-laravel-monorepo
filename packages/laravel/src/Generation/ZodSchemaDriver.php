<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;

final readonly class ZodSchemaDriver implements SchemaDriver
{
    public function imports(): string
    {
        return "import {z} from 'zod';";
    }

    public function object(array $parameters, ?array $cursor = null, bool $public = false): string
    {
        $fields = [
            '__route: z.string().optional()',
            '__routeArgs: z.record(z.string(), z.union([z.string(), z.number().int()])).optional()',
            '__window: z.number().int().min(1).max(2147483647).optional()',
        ];
        foreach ($parameters as $name => $type) {
            $fields[] = json_encode($name, JSON_THROW_ON_ERROR).': '.$this->schema($type, $public);
        }

        if ($cursor !== null) {
            $columns = [];
            foreach ($cursor as $name => $type) {
                $columns[] = json_encode($name, JSON_THROW_ON_ERROR).': '.$this->schema($type);
            }

            $fields[] = '__cursor: z.strictObject({'.implode(', ', $columns).'}).optional()';
        }

        return 'z.strictObject({'.implode(', ', $fields).'})';
    }

    public function parse(string $schema, string $input): string
    {
        return $schema.'.parse('.$input.')';
    }

    public function inputType(string $schema): string
    {
        return 'z.input<typeof '.$schema.'>';
    }

    public function encode(array $parameters, string $input): string
    {
        $fields = ['...'.$input];
        foreach ($parameters as $name => $type) {
            if (str_starts_with($type, 'request:')) {
                $key = json_encode($name, JSON_THROW_ON_ERROR);
                $fields[] = $key.': '.RequestContract::value(substr($type, 8))->encode($input.'['.$key.']');
            }
        }

        return '({'.implode(', ', $fields).'})';
    }

    private function schema(string $type, bool $public = false): string
    {
        if (str_starts_with($type, 'nullable:')) {
            return $this->schema(substr($type, 9), $public).'.nullable()';
        }

        if (str_starts_with($type, 'request:')) {
            $contract = RequestContract::value(substr($type, 8));

            return (new Mutations\ZodMutationSchemaDriver)->value($public ? $contract : $contract->wire());
        }

        if (str_starts_with($type, 'enum:')) {
            return 'z.union(['.implode(', ', array_map(fn (string|int $value): string => 'z.literal('.json_encode($value, JSON_THROW_ON_ERROR).')', \ZeroForLaravel\Query\QueryInput::enumValues($type))).'])';
        }

        return match (\ZeroForLaravel\Query\QueryInput::scalarType($type)) {
            'string' => 'z.string()',
            'bool' => 'z.boolean()',
            'float' => 'z.number().finite()',
            'int' => 'z.number().int().min(-9007199254740991).max(9007199254740991)',
            default => throw new LogicException("Unsupported public input type {$type}."),
        };
    }
}
