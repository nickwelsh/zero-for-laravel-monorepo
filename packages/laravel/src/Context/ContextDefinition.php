<?php

declare(strict_types=1);

namespace ZeroForLaravel\Context;

use BackedEnum;
use Illuminate\Http\Request;
use LogicException;
use ReflectionClass;
use ReflectionEnum;
use ReflectionNamedType;
use ZeroForLaravel\Attributes\ResolvesContext;
use ZeroForLaravel\ResolvedContext;

/** Static field contracts are reusable; resolved values are always invocation-local. */
final readonly class ContextDefinition
{
    /** @var array<string, array{method: string, type: 'string'|'int'|'float'|'bool', nullable: bool}> */
    public array $fields;

    /** @param class-string<BackedEnum> $enum
     * @param  class-string  $resolver
     */
    public function __construct(public string $enum, public string $resolver)
    {
        if (! enum_exists($enum) || (new ReflectionEnum($enum))->getBackingType()?->getName() !== 'string') {
            throw new LogicException('zero.context must name an application-owned string-backed enum.');
        }

        $fields = [];
        foreach ((new ReflectionClass($resolver))->getMethods() as $method) {
            foreach ($method->getAttributes(ResolvesContext::class) as $attribute) {
                $field = $attribute->newInstance()->field;
                $type = $method->getReturnType();
                if (! $field instanceof $enum || ! is_string($field->value) || isset($fields[$field->value])) {
                    throw new LogicException($resolver.'::'.$method->name.': duplicate or foreign context field.');
                }

                if (! $method->isPublic() || $method->isStatic() || ! $type instanceof ReflectionNamedType || ! in_array($type->getName(), ['string', 'int', 'float', 'bool'], true)) {
                    throw new LogicException($resolver.'::'.$method->name.': context methods must be public instance methods returning string, int, float, bool, or their nullable forms.');
                }

                foreach ($method->getParameters() as $parameter) {
                    if ($parameter->isPassedByReference() || $parameter->isVariadic()) {
                        throw new LogicException($resolver.'::'.$method->name.': context dependencies cannot be variadic or by-reference.');
                    }
                }

                $fields[$field->value] = ['method' => $method->name, 'type' => $type->getName(), 'nullable' => $type->allowsNull()];
            }
        }

        foreach ($enum::cases() as $case) {
            if (! is_string($case->value) || ! isset($fields[$case->value])) {
                throw new LogicException('Missing #[ResolvesContext] mapping for '.$enum.'::'.$case->name.'.');
            }
        }

        ksort($fields);
        $this->fields = $fields;
    }

    public static function configured(): self
    {
        $enum = config()->string('zero.context');
        $resolver = config()->string('zero.context_resolver');
        if (! is_subclass_of($enum, BackedEnum::class) || ! class_exists($resolver)) {
            throw new LogicException('Configure zero.context with an enum class and zero.context_resolver with a resolver class.');
        }

        return new self($enum, $resolver);
    }

    public function resolve(Request $request): ResolvedContext
    {
        $resolver = resolve($this->resolver);
        $values = [];
        foreach ($this->fields as $field => $contract) {
            $callback = [$resolver, $contract['method']];
            if (! is_callable($callback)) {
                throw new LogicException('Context resolver method is not callable.');
            }

            $values[$field] = app()->call($callback, ['request' => $request]);
        }

        $this->validate($values);
        $id = $request->user()?->getAuthIdentifier();
        if ($id !== null && ! is_string($id) && ! is_int($id)) {
            throw new LogicException('Authenticated Zero identity must be a string or integer.');
        }

        return new ResolvedContext($values, $id === null ? null : (string) $id);
    }

    /** @param array<string, mixed> $values */
    public function validate(array $values): void
    {
        if (array_diff(array_keys($values), array_keys($this->fields)) !== []) {
            throw new LogicException('Unknown context fields.');
        }

        foreach ($this->fields as $field => $contract) {
            if (! array_key_exists($field, $values)) {
                throw new LogicException('Missing context field '.$field.'.');
            }

            $value = $values[$field];
            $valid = $value === null ? $contract['nullable'] : match ($contract['type']) {
                'string' => is_string($value),
                'int' => is_int($value) && abs($value) <= 9007199254740991,
                'float' => (is_int($value) || is_float($value)) && is_finite((float) $value),
                'bool' => is_bool($value),
            };
            if (! $valid) {
                throw new LogicException('Invalid context value for '.$field.'.');
            }
        }
    }

    public function typescript(): string
    {
        $fields = [];
        foreach ($this->fields as $key => $field) {
            $type = match ($field['type']) {
                'int', 'float' => 'number', 'bool' => 'boolean', default => 'string',
            };
            $fields[] = 'readonly '.json_encode($key, JSON_THROW_ON_ERROR).': '.$type.($field['nullable'] ? ' | null' : '').';';
        }

        return '{'.implode(' ', $fields).'}';
    }

    /** @return array<string, array{type: string, nullable: bool}> */
    public function contract(): array
    {
        return array_map(static fn (array $field): array => ['type' => $field['type'], 'nullable' => $field['nullable']], $this->fields);
    }
}
