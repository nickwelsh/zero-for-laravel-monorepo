<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use LogicException;
use Ramsey\Uuid\Uuid;
use ZeroForLaravel\Context\ContextReference;
use ZeroForLaravel\Metadata\ModelMetadata;
use ZeroForLaravel\Metadata\ModelRegistry;
use ZeroForLaravel\Query\ZeroQuery;
use ZeroForLaravel\ResolvedContext;
use ZeroForLaravel\Support\Input;

/** Invocation-local stable metadata. Never placed in FormRequest business input. */
final readonly class Mutation
{
    /**
     * @param  array{ids:array<string,string>,timestamp:int}  $prepared
     * @param  Closure(Model):Model|null  $rebind
     */
    public function __construct(private ModelRegistry $models, private array $prepared, private ResolvedContext $context, private ?Closure $rebind = null) {}

    /**
     * @template T
     *
     * @param  Closure():T  $guard
     * @return T
     */
    public static function server(Closure $guard): mixed
    {
        return $guard();
    }

    public function context(string $field): string|int|float|bool|null
    {
        return $this->context->get($field);
    }

    public function timestamp(): int
    {
        return $this->prepared['timestamp'];
    }

    /** Derive creation identities from prepared per-site seeds, never traversal positions. */
    public function forItem(string $key): self
    {
        $ids = array_map(fn (string $seed): string => Uuid::uuid5($seed, $key)->toString(), $this->prepared['ids']);

        return new self($this->models, ['ids' => $ids, 'timestamp' => $this->timestamp()], $this->context, $this->rebind);
    }

    public function metadata(string $class): ModelMetadata
    {
        foreach ($this->models->all() as $metadata) {
            if ($metadata->class === $class) {
                return $metadata;
            }
        }

        throw new LogicException('Unregistered mutation model '.$class);
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $data
     * @param  array<string,mixed>|null  $defaults
     * @return T
     */
    public function create(string $class, array $data, string $key, ?array $defaults = null): Model
    {
        $meta = $this->metadata($class);
        $model = new $class;
        $this->connection($model);
        // Preserve Eloquent mass assignment; prepared keys are not request fields.
        $defaults ??= (new ZeroQuery($meta, $this->models))->pendingAttributes();
        $data = Input::object(ContextReference::resolve(ContextReference::normalize([...$defaults, ...$data]), $this->context), 'data');
        Input::keys($data, $meta->writable, 'data');
        $model->fill($data);

        if ($meta->idGenerator !== null) {
            $site = array_key_exists($key, $this->prepared['ids']) ? $key : 'key:'.$key;
            if (! isset($this->prepared['ids'][$site])) {
                throw new LogicException('Creation site was not prepared; use generated optimism or ordinary authoritative Eloquent creation.');
            }

            $model->setAttribute($meta->key, $this->prepared['ids'][$site]);
        }

        foreach (array_filter([$meta->createdAt, $meta->updatedAt]) as $field) {
            $model->setAttribute($field, Date::createFromTimestampMs($this->timestamp()));
        }

        if (! $model->saveOrFail()) {
            Input::fail('model', 'An Eloquent event cancelled this mutation.');
        }

        return $model;
    }

    /**
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public function reloadForUpdate(Model $model): Model
    {
        $this->connection($model);

        $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
        $result = $this->rebind instanceof Closure ? ($this->rebind)($locked) : $locked;
        if (! $result instanceof $model) {
            throw new LogicException('Rebinding changed the model type.');
        }

        return $result;
    }

    /** @param array<string,mixed> $data */
    public function update(Model $model, array $data): bool
    {
        $this->connection($model);
        $meta = $this->metadata($model::class);
        Input::keys($data, $meta->writable, 'data');
        $model->fill(Input::object(ContextReference::resolve(ContextReference::normalize($data), $this->context), 'data'));
        if ($meta->updatedAt !== null) {
            $model->setAttribute($meta->updatedAt, Date::createFromTimestampMs($this->timestamp()));
        }

        if (! $model->saveOrFail()) {
            Input::fail('model', 'An Eloquent event cancelled this mutation.');
        }

        return true;
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $unique
     * @return T|null
     */
    public function findUnique(string $class, array $unique): ?Model
    {
        $unique = Input::object(ContextReference::resolve(ContextReference::normalize($unique), $this->context), 'unique');
        $model = new $class;
        $this->connection($model);
        UniqueMutationKey::validate($this->metadata($class), array_keys($unique));
        foreach ($unique as $value) {
            if (! is_string($value) && ! is_int($value) && ! is_bool($value)) {
                throw new LogicException('Unique key values must be nonnull scalars.');
            }
        }

        return $model->newQuery()->where($unique)->first();
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $unique
     * @param  array<string,mixed>  $data
     * @return T
     */
    public function insertUnlessExists(string $class, array $unique, array $data, string $key): Model
    {
        Input::keys($data, $this->metadata($class)->writable, 'data');
        UniqueMutationKey::validate($this->metadata($class), array_keys($unique), true);
        if (array_intersect_key($data, $unique) !== []) {
            throw new LogicException('Unique keys and write fields must be disjoint.');
        }

        $existing = $this->findUnique($class, $unique);
        if ($existing instanceof Model) {
            return $existing;
        }

        try {
            // A savepoint is essential: PostgreSQL aborts the transaction after a
            // conflict. Eloquent events still run; losing inserts never fire created.
            return $this->models->connection()->transaction(function () use ($class, $unique, $data, $key): Model {
                $created = $this->create($class, $unique + $data, $key);
                if (! $created->exists) {
                    throw new LogicException('Preference insertion was cancelled by a model event.');
                }

                return $created;
            });
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            // READ COMMITTED sees the committed winner; stricter isolation can retry
            // the native transaction rather than inventing an authoritative absence.
            return $this->findUnique($class, $unique) ?? throw $uniqueConstraintViolationException;
        }
    }

    /**
     * @template T of Model
     *
     * @param  class-string<T>  $class
     * @param  array<string,mixed>  $unique
     * @param  array<string,mixed>  $data  Explicit fields to insert/update; defaults are never update fields.
     * @return T
     */
    public function upsertUnique(string $class, array $unique, array $data, string $key): Model
    {
        $model = $this->insertUnlessExists($class, $unique, $data, $key);
        if (! $model->wasRecentlyCreated && ! $this->update($model, $data)) {
            throw new LogicException('Preference update was cancelled by a model event.');
        }

        return $model;
    }

    public function delete(Model $model): bool
    {
        $this->connection($model);
        if ($this->metadata($model::class)->deletedAt !== null) {
            throw new LogicException('Portable delete does not support soft deletes.');
        }

        if ($model->delete() !== true) {
            Input::fail('model', 'An Eloquent event cancelled this mutation.');
        }

        return true;
    }

    private function connection(Model $model): void
    {
        if ($model->getConnection() !== $this->models->connection() || $model->getConnection()->transactionLevel() === 0) {
            throw new LogicException('Controller mutations require the native upstream transaction and connection.');
        }
    }
}
