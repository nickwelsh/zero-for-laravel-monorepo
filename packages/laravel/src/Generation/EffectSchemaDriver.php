<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use LogicException;
use ZeroForLaravel\Generation\Mutations\MutationSchemaDriver;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Query\QueryInput;

/** Native Effect 4 schemas with synchronous Zero compatibility at the generated boundary. */
final readonly class EffectSchemaDriver implements MutationSchemaDriver
{
    public function imports(): string
    {
        return <<<'TS'
import * as EffectSchema from 'effect/Schema';
import * as EffectSchemaIssue from 'effect/SchemaIssue';
function zeroSchema<S extends EffectSchema.ConstraintDecoder<unknown>>(schema: S) {
    const options = {errors: 'all', onExcessProperty: 'error'} as const;
    const decode = EffectSchema.decodeUnknownSync(schema, options);
    return Object.assign(EffectSchema.toStandardSchemaV1(schema, {parseOptions: options}), {
        parse(input: unknown): S['Type'] {
            try {
                return decode(input);
            } catch (cause) {
                if (!EffectSchema.isSchemaError(cause)) throw cause;
                throw Object.assign(new Error(cause.message, {cause}), EffectSchemaIssue.makeFormatterStandardSchemaV1()(cause.issue));
            }
        },
    });
}
TS;
    }

    public function object(array $parameters, ?array $cursor = null, bool $public = false, array $contracts = [], bool $routed = true, bool $window = false): string
    {
        $fields = $routed ? [
            '__route: EffectSchema.optional(EffectSchema.String)',
            '__routeArgs: EffectSchema.optional(EffectSchema.Record(EffectSchema.String, EffectSchema.Union([EffectSchema.String, EffectSchema.Int])))',
        ] : [];
        if ($routed || $window) {
            $fields[] = '__window: EffectSchema.optional(EffectSchema.Int.check(EffectSchema.isGreaterThanOrEqualTo(1), EffectSchema.isLessThanOrEqualTo(2147483647)))';
        }

        if (! $public) {
            $fields[] = '__generation: EffectSchema.optional('.$this->schema(new ValueContract('string', rules: ['uuid'])).')';
        }

        foreach ($parameters as $name => $type) {
            $fields[] = json_encode($name, JSON_THROW_ON_ERROR).': '.$this->schema($contracts[$name] ?? $this->parameter($type, $public));
        }

        if ($cursor !== null) {
            $columns = [];
            foreach ($cursor as $name => $type) {
                $columns[] = json_encode($name, JSON_THROW_ON_ERROR).': '.$this->schema($this->parameter($type));
            }

            $fields[] = '__cursor: EffectSchema.optional('.$this->objectSchema($columns).')';
        }

        return 'zeroSchema('.$this->objectSchema($fields).')';
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

    public function inputType(string $schema): string
    {
        return '(typeof '.$schema.')["Encoded"]';
    }

    public function parse(string $schema, string $input): string
    {
        return $schema.'.parse('.$input.')';
    }

    public function value(ValueContract $contract): string
    {
        return 'zeroSchema('.$this->schema($contract).')';
    }

    private function parameter(string $type, bool $public = false): ValueContract
    {
        if (str_starts_with($type, 'nullable:')) {
            $inner = $this->parameter(substr($type, 9), $public);

            return new ValueContract($inner->kind, $inner->fields, $inner->members, $inner->optional, true, $inner->rules);
        }

        if (str_starts_with($type, 'request:')) {
            $contract = RequestContract::value(substr($type, 8));

            return $public ? $contract : $contract->wire();
        }

        if (str_starts_with($type, 'enum:')) {
            $choices = QueryInput::enumValues($type);

            return new ValueContract(is_int($choices[0] ?? null) ? 'int' : 'string', rules: ['choices:'.json_encode($choices, JSON_THROW_ON_ERROR)]);
        }

        $kind = match (QueryInput::scalarType($type)) {
            'string' => 'string', 'bool' => 'bool', 'float' => 'number', 'int' => 'int',
            default => throw new LogicException('Unsupported public input type '.$type),
        };

        return new ValueContract($kind);
    }

    /** @param list<string> $fields */
    private function objectSchema(array $fields): string
    {
        // Effect's empty Struct accepts any non-nullish value, unlike a JSON object contract.
        return $fields === [] ? 'EffectSchema.Record(EffectSchema.String, EffectSchema.Never)' : 'EffectSchema.Struct({'.implode(', ', $fields).'})';
    }

    private function schema(ValueContract $contract): string
    {
        $schema = match ($contract->kind) {
            'dateWire' => 'EffectSchema.String.check(EffectSchema.isPattern(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/), EffectSchema.makeFilter(value => !Number.isNaN(new Date(value).getTime()) && new Date(value).toISOString() === value, {expected: "an ISO datetime with millisecond precision"}))',
            'dateOnlyWire' => 'EffectSchema.String.check(EffectSchema.isPattern(/^\d{4}-\d{2}-\d{2}$/), EffectSchema.makeFilter(value => !Number.isNaN(new Date(value + "T00:00:00.000Z").getTime()) && new Date(value + "T00:00:00.000Z").toISOString().slice(0, 10) === value, {expected: "an ISO date"}))',
            'date' => 'EffectSchema.Date',
            'dateOnly' => 'EffectSchema.Date.check(EffectSchema.makeFilter(value => value.getUTCFullYear() >= 0 && value.getUTCFullYear() <= 9999, {expected: "a date in years 0000 through 9999"}))',
            'string' => 'EffectSchema.String', 'int' => 'EffectSchema.Int', 'number' => 'EffectSchema.Finite',
            'bool' => 'EffectSchema.Boolean', 'null' => 'EffectSchema.Null', 'void' => 'EffectSchema.Undefined',
            'object' => $this->objectSchema(array_map(fn (string $key, ValueContract $field): string => json_encode($key, JSON_THROW_ON_ERROR).': '.$this->schema($field), array_keys($contract->fields), array_values($contract->fields))),
            'tuple' => 'EffectSchema.Tuple(['.implode(', ', array_map($this->schema(...), $contract->members)).'])',
            'list' => 'EffectSchema.Array('.$this->schema($contract->members[0]).')',
            'union' => 'EffectSchema.Union(['.implode(', ', array_map($this->schema(...), $contract->members)).'])',
            default => throw new LogicException('Unsupported mutation schema '.$contract->kind),
        };
        foreach ($contract->rules as $rule) {
            [$name, $value] = array_pad(explode(':', $rule, 2), 2, null);
            if (in_array($contract->kind, ['string', 'int', 'number', 'list'], true) && in_array($name, ['min', 'max'], true) && $value !== null && ctype_digit($value)) {
                $bound = (int) $value;
                $schema .= match ($contract->kind) {
                    'string' => '.check(EffectSchema.makeFilter(value => Array.from(value).length '.($name === 'min' ? '>=' : '<=').' '.$bound.', {expected: "a string with '.($name === 'min' ? 'at least' : 'at most').' '.$bound.' code points"}))',
                    'list' => '.check(EffectSchema.'.($name === 'min' ? 'isMinLength' : 'isMaxLength').'('.$bound.'))',
                    default => '.check(EffectSchema.'.($name === 'min' ? 'isGreaterThanOrEqualTo' : 'isLessThanOrEqualTo').'('.$bound.'))',
                };
            }

            if ($contract->kind === 'string' && $name === 'uuid') {
                $schema .= '.check(EffectSchema.isPattern(/^([0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-8][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}|00000000-0000-0000-0000-000000000000|ffffffff-ffff-ffff-ffff-ffffffffffff)$/, {expected: "a UUID"}))';
            }

            if ($contract->kind === 'string' && $name === 'ulid') {
                $schema .= '.check(EffectSchema.isULID(), EffectSchema.isPattern(/^[0-7]/, {expected: "a ULID without timestamp overflow"}))';
            }
        }

        if (($choices = $contract->choices()) !== null) {
            $schema = 'EffectSchema.Literals('.json_encode($choices, JSON_THROW_ON_ERROR).').check(EffectSchema.makeFilter(EffectSchema.is('.$schema.')))';
        }

        if ($contract->nullable && $contract->kind !== 'null') {
            $schema = 'EffectSchema.NullOr('.$schema.')';
        }

        return $contract->optional ? 'EffectSchema.optional('.$schema.')' : $schema;
    }
}
