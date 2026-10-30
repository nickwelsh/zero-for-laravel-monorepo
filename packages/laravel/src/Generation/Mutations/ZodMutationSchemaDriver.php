<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation\Mutations;

use LogicException;
use ZeroForLaravel\Generation\ZodSchemaDriver;

final readonly class ZodMutationSchemaDriver implements MutationSchemaDriver
{
    public function imports(): string
    {
        return (new ZodSchemaDriver)->imports();
    }

    public function object(array $parameters, ?array $cursor = null, bool $public = false): string
    {
        return (new ZodSchemaDriver)->object($parameters, $cursor, $public);
    }

    public function encode(array $parameters, string $input): string
    {
        return (new ZodSchemaDriver)->encode($parameters, $input);
    }

    public function parse(string $schema, string $input): string
    {
        return (new ZodSchemaDriver)->parse($schema, $input);
    }

    public function inputType(string $schema): string
    {
        return (new ZodSchemaDriver)->inputType($schema);
    }

    public function value(ValueContract $contract): string
    {
        $schema = match ($contract->kind) {
            'dateWire' => 'z.iso.datetime({precision: 3})',
            'dateOnlyWire' => 'z.iso.date()',
            'date' => 'z.date()',
            'dateOnly' => 'z.date().refine(value => value.getUTCFullYear() >= 0 && value.getUTCFullYear() <= 9999)',
            'string' => 'z.string()', 'int' => 'z.number().int().min(-9007199254740991).max(9007199254740991)',
            'number' => 'z.number().finite()', 'bool' => 'z.boolean()', 'null' => 'z.null()', 'void' => 'z.undefined()',
            'object' => 'z.strictObject({'.implode(', ', array_map(fn (string $key, ValueContract $field): string => json_encode($key, JSON_THROW_ON_ERROR).': '.$this->value($field), array_keys($contract->fields), array_values($contract->fields))).'})',
            'tuple' => 'z.tuple(['.implode(', ', array_map($this->value(...), $contract->members)).'])',
            'list' => 'z.array('.$this->value($contract->members[0]).')',
            'union' => 'z.union(['.implode(', ', array_map($this->value(...), $contract->members)).'])',
            default => throw new LogicException('Unsupported mutation schema '.$contract->kind),
        };
        foreach ($contract->rules as $rule) {
            [$name, $value] = array_pad(explode(':', $rule, 2), 2, null);
            if (in_array($contract->kind, ['string', 'int', 'number', 'list'], true) && in_array($name, ['min', 'max'], true) && $value !== null && ctype_digit($value)) {
                $bound = (int) $value;
                $schema .= $contract->kind === 'string'
                    ? '.refine(value => Array.from(value).length '.($name === 'min' ? '>=' : '<=').' '.$bound.')'
                    : '.'.$name.'('.$bound.')';
            }

            if ($contract->kind === 'string' && in_array($name, ['uuid', 'ulid'], true)) {
                $schema .= '.'.$name.'()';
            }
        }

        return $schema.($contract->nullable && $contract->kind !== 'null' ? '.nullable()' : '').($contract->optional ? '.optional()' : '');
    }
}
