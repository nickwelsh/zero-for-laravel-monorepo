<?php

declare(strict_types=1);

namespace ZeroForLaravel\Query;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use ZeroForLaravel\Generation\Mutations\ValueContract;
use ZeroForLaravel\Support\Input;

/** The canonical wire value is distinct from a route's slug or bound object. */
final class QueryInput
{
    public static function scalarType(string $type): string
    {
        if (str_starts_with($type, 'model:')) {
            $class = substr($type, 6);
            if (is_subclass_of($class, Model::class)) {
                return (new $class)->getKeyType() === 'int' ? 'int' : 'string';
            }
        }

        return $type;
    }

    /** @return list<string|int> */
    public static function enumValues(string $type): array
    {
        $class = substr($type, 5);

        return is_subclass_of($class, BackedEnum::class) ? array_map(fn (BackedEnum $case): string|int => $case->value, $class::cases()) : [];
    }

    public static function validate(string $type, mixed $value, string $name): void
    {
        if (str_starts_with($type, 'request:')) {
            Input::object($value, 'args.'.$name);

            return;
        }

        $valid = str_starts_with($type, 'enum:') ? in_array($value, self::enumValues($type), true) : match (self::scalarType($type)) {
            'string' => is_string($value),
            'bool' => is_bool($value),
            'float' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'int' => is_int($value) && abs($value) <= 9007199254740991,
            default => false,
        };
        if (! $valid) {
            Input::fail('args.'.$name, 'Missing or invalid query argument.');
        }
    }

    /** Strict wire shapes; FormRequest still owns authoritative rules and authorization. */
    public static function validateRequest(ValueContract $contract, mixed $value, string $path): void
    {
        if ($value === null && $contract->nullable) {
            return;
        }

        if ($contract->kind === 'object') {
            $values = Input::object($value, $path);
            Input::keys($values, array_keys($contract->fields), $path);
            foreach ($contract->fields as $name => $field) {
                if (! array_key_exists($name, $values)) {
                    if ($field->optional) {
                        continue;
                    }

                    Input::fail($path.'.'.$name, 'Missing query input.');
                }

                self::validateRequest($field, $values[$name], $path.'.'.$name);
            }

            return;
        }

        if ($contract->kind === 'list' && is_array($value) && array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::validateRequest($contract->members[0], $item, $path.'.'.$index);
            }

            return;
        }

        $valid = match ($contract->kind) {
            'string', 'date', 'dateOnly' => is_string($value),
            'int' => is_int($value) && abs($value) <= 9007199254740991,
            'number' => (is_int($value) || is_float($value)) && is_finite((float) $value),
            'bool' => is_bool($value),
            default => false,
        };
        if (! $valid) {
            Input::fail($path, 'Invalid query input type.');
        }
    }

    public static function bound(string $type, mixed $value): mixed
    {
        if (str_starts_with($type, 'model:')) {
            $class = substr($type, 6);
            if (! $value instanceof Model || ! $value instanceof $class) {
                Input::fail('route', 'Expected the registered bound model.');
            }

            return $value->getKey();
        }

        if (str_starts_with($type, 'enum:')) {
            $class = substr($type, 5);
            if (! $value instanceof BackedEnum || ! $value instanceof $class) {
                Input::fail('route', 'Expected the registered bound enum.');
            }

            return $value->value;
        }

        if (! is_scalar($value)) {
            Input::fail('route', 'Unexpected bound query argument.');
        }

        return match ($type) {
            'string' => (string) $value,
            'bool' => (bool) $value,
            'int' => filter_var($value, FILTER_VALIDATE_INT),
            default => Input::fail('route', 'Unsupported query input codec.'),
        };
    }

    /** @param array<string, string> $parameters
     * @param  array<string,ValueContract>  $contracts
     * @return array<string, mixed>
     */
    public static function arguments(array $parameters, Request $request, Route $route, array $contracts = []): array
    {
        $values = array_values($route->parametersWithoutNulls());
        $index = 0;
        $args = [];
        foreach ($parameters as $name => $type) {
            if (str_starts_with($type, 'request:')) {
                $class = substr($type, 8);
                $form = $request->attributes->get('zero.form.'.$class) ?? resolve($class);
                if (! $form instanceof FormRequest) {
                    Input::fail('request', 'Expected the registered FormRequest.');
                }

                $validated = $form->validated();
                $args[$name] = Input::object(isset($contracts[$name]) ? $contracts[$name]->encodeValidated($validated) : $validated, 'validated');
                ksort($args[$name]);
            } else {
                $args[$name] = self::bound($type, $values[$index++] ?? null);
            }
        }

        if ($index !== count($values)) {
            Input::fail('route', 'Route binding does not match the query contract.');
        }

        return $args;
    }
}
