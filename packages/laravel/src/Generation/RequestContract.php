<?php

declare(strict_types=1);

namespace ZeroForLaravel\Generation;

use Illuminate\Foundation\Http\FormRequest;
use Laravel\Surveyor\Analyzer\Analyzer;
use Laravel\Surveyor\Types\ArrayType;
use Laravel\Surveyor\Types\StringType;
use LogicException;
use ZeroForLaravel\Generation\Mutations\ValueContract;

/** Static request shapes; Laravel still executes all authoritative rules and hooks. */
final class RequestContract
{
    /** @return array<string, list<string>> */
    public static function rules(string $class): array
    {
        if (! is_subclass_of($class, FormRequest::class)) {
            throw new LogicException('Expected a FormRequest contract.');
        }

        $analyzer = resolve(Analyzer::class);
        $analyzer->analyzeClass($class);

        $result = $analyzer->result();
        $type = $result instanceof \Laravel\Surveyor\Analyzed\ClassLikeResult ? $result->getMethod('rules')?->returnType() : null;
        if (! $type instanceof ArrayType) {
            throw new LogicException($class.': rules() must have a statically known field shape.');
        }

        $choices = RequestChoices::forRequest($class);
        $fields = [];
        foreach ($type->value as $field => $rules) {
            if (! is_string($field)) {
                throw new LogicException($class.': request fields must have string names.');
            }

            $items = $rules instanceof ArrayType ? $rules->value : [$rules];
            $fields[$field] = [];
            foreach ($items as $rule) {
                if ($rule instanceof StringType && $rule->value !== null) {
                    array_push($fields[$field], ...explode('|', $rule->value));
                }
            }

            if (isset($choices[$field])) {
                if ($choices[$field] === []) {
                    throw new LogicException('Finite input choices cannot be empty.');
                }

                $fields[$field][] = 'choices:'.json_encode($choices[$field], JSON_THROW_ON_ERROR);
                $fields[$field][] = is_int($choices[$field][0]) ? 'integer' : 'string';
            }

            self::type($fields[$field]);
        }

        return $fields;
    }

    public static function value(string $class): ValueContract
    {
        $rules = self::rules($class);
        $field = function (string $path) use (&$field, $rules): ValueContract {
            $constraints = $rules[$path] ?? throw new LogicException('Missing validation contract for '.$path);
            $kind = self::type($constraints);
            $fields = [];
            $members = [];
            if ($kind === 'array') {
                if (isset($rules[$path.'.*'])) {
                    if (! in_array('list', $constraints, true)) {
                        throw new LogicException($path.': repeated inputs require the Laravel list rule.');
                    }

                    $kind = 'list';
                    $members[] = $field($path.'.*');
                } else {
                    $kind = 'object';
                    foreach (array_keys($rules) as $key) {
                        if (str_starts_with($key, $path.'.') && ! str_contains(substr($key, strlen($path) + 1), '.')) {
                            $fields[substr($key, strlen($path) + 1)] = $field($key);
                        }
                    }

                    if ($fields === []) {
                        throw new LogicException($path.': arrays require statically declared child rules.');
                    }
                }
            }

            return new ValueContract($kind, fields: $fields, members: $members,
                optional: in_array('sometimes', $constraints, true) || (! in_array('required', $constraints, true) && ! in_array('present', $constraints, true)),
                nullable: in_array('nullable', $constraints, true), rules: $constraints);
        };
        $fields = [];
        foreach (array_keys($rules) as $key) {
            if (! str_contains($key, '.')) {
                $fields[$key] = $field($key);
            }
        }

        return new ValueContract('object', fields: $fields);
    }

    /** @param list<string> $rules */
    public static function type(array $rules): string
    {
        foreach (['date_format:Y-m-d' => 'dateOnly', 'date' => 'date', 'string' => 'string', 'uuid' => 'string', 'ulid' => 'string', 'integer' => 'int', 'numeric' => 'number', 'boolean' => 'bool', 'array' => 'array'] as $rule => $type) {
            if (in_array($rule, $rules, true)) {
                return $type;
            }
        }

        throw new LogicException('Request field requires an explicit supported scalar validation type.');
    }
}
