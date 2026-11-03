<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use stdClass;

/** @phpstan-type Attribute array{column: string, type: 'id'|'string'|'boolean'|'integer'} */
final readonly class Binding
{
    /**
     * @param  class-string<Model>  $class
     * @param  array<string, Attribute>  $attributes
     */
    public function __construct(
        public string $class,
        public string $type,
        public string $model,
        public ?string $domainColumn,
        public string $domainPrefix,
        public array $attributes,
    ) {}

    /** @param class-string<Model> $class */
    public static function resource(string $class): self
    {
        $bindings = config()->array('permissions.resources');
        $value = $bindings[$class] ?? null;
        if (! is_array($value) || ! is_string($value['type'] ?? null)) {
            throw new LogicException("Missing permissions resource binding for {$class}.");
        }

        $model = $value['model'] ?? 'default';
        $domain = $value['domain'] ?? [];
        if (! is_array($domain)) {
            throw new LogicException("Invalid permissions domain binding for {$class}.");
        }

        $column = $domain['column'] ?? null;
        $prefix = $domain['prefix'] ?? '';
        if (! is_string($model) || ($column !== null && ! is_string($column)) || ! is_string($prefix)) {
            throw new LogicException("Invalid permissions domain/model binding for {$class}.");
        }

        return new self($class, $value['type'], $model, $column, $prefix, self::attributes($value['attributes'] ?? []));
    }

    /** @return array<string, Attribute> */
    public static function attributes(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('Permission attributes must map names to database columns and scalar types.');
        }

        $attributes = [];
        foreach ($value as $name => $attribute) {
            if (! is_string($name) || ! is_array($attribute) || ! is_string($attribute['column'] ?? null)
                || ! in_array($attribute['type'] ?? null, ['id', 'string', 'boolean', 'integer'], true)
                || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name)
                || in_array($name, ['id', 'key', 'type'], true)) {
                throw new LogicException('Invalid permission attribute binding. Reserved attributes are id, key and type.');
            }

            $attributes[$name] = ['column' => $attribute['column'], 'type' => $attribute['type']];
        }

        return $attributes;
    }

    public static function subject(Authenticatable $user): stdClass
    {
        $class = config()->string('permissions.subject.class');
        if (! $user instanceof Model || ! $user instanceof $class) {
            throw new LogicException('Permission subjects must use the configured database-backed Eloquent user class.');
        }

        $prefix = config()->string('permissions.subject.prefix');
        $id = self::id($user->getAuthIdentifier());

        return (object) ['id' => $id, 'key' => $prefix.$id, ...self::facts($user, self::attributes(config()->get('permissions.subject.attributes')))];
    }

    public static function id(mixed $value): string
    {
        if ((! is_int($value) && ! is_string($value)) || (string) $value === '' || strlen((string) $value) > 200) {
            throw new LogicException('Permission identifiers must be nonempty strings or integers of at most 200 bytes.');
        }

        return (string) $value;
    }

    /** @param array<string, Attribute> $attributes
     * @return array<string, bool|int|string>
     */
    public static function facts(Model $model, array $attributes): array
    {
        $facts = [];
        foreach ($attributes as $name => $attribute) {
            $value = $model->getRawOriginal($attribute['column']);
            $facts[$name] = match ($attribute['type']) {
                'id' => self::id($value),
                'string' => is_string($value) ? $value : throw new LogicException("{$name} must be a non-null database string."),
                'boolean' => in_array($value, [true, false, 0, 1], true) ? (bool) $value : throw new LogicException("{$name} must be a non-null database boolean."),
                'integer' => is_int($value) && abs($value) <= 9007199254740991 ? $value : throw new LogicException("{$name} must be a safe non-null database integer."),
            };
        }

        return $facts;
    }

    public function domain(Model $resource): string
    {
        return $this->domainColumn === null ? '__global__' : $this->domainPrefix.self::id($resource->getRawOriginal($this->domainColumn));
    }

    public function object(Model $resource): stdClass
    {
        return (object) ['type' => $this->type, 'id' => self::id($resource->getKey()), ...self::facts($resource, $this->attributes)];
    }
}
