<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Casbin\Enforcer;
use Casbin\Model\Model as CasbinModel;
use Casbin\Rbac\DefaultRoleManager\DomainManager;
use Casbin\Rbac\DefaultRoleManager\RoleManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class PermissionManager
{
    public function __construct(private string $name = 'default') {}

    public function model(string $name): self
    {
        return new self($name);
    }

    public function connection(): Connection
    {
        $name = config()->get('permissions.connection');
        if ($name !== null && ! is_string($name)) {
            throw new LogicException('permissions.connection must be a connection name or null.');
        }

        return DB::connection($name);
    }

    public function source(): string
    {
        $path = config()->array('permissions.models')[$this->name] ?? null;
        if (! is_string($path) || ! is_file($path) || ($text = file_get_contents($path)) === false) {
            throw new LogicException("Missing Casbin model {$this->name}.");
        }

        return $text;
    }

    public function inspect(Authenticatable $user, string $action, Model $resource): AuthorizationResponse
    {
        $binding = Binding::resource($resource::class);
        $manager = $this->model($binding->model);
        // Do not cache authorization results or the caller's mutable attributes.
        $freshUser = $user instanceof Model ? $user->fresh() : null;
        $freshResource = $resource->fresh();
        $allowed = false;
        if ($freshUser instanceof Authenticatable && $freshResource instanceof Model) {
            $subject = Binding::subject($freshUser);
            $object = $binding->object($freshResource);
            $enforcer = $manager->enforcer($binding);
            $fields = $enforcer->getModel()['r']['r']->tokens ?? throw new LogicException('Missing Casbin request definition.');
            $allowed = in_array('r_dom', $fields, true)
                ? $enforcer->enforce($subject, $binding->domain($freshResource), $object, $action)
                : $enforcer->enforce($subject, $object, $action);
        }

        $status = config()->integer('permissions.denied_status');
        if (! in_array($status, [403, 404], true)) {
            throw new LogicException('permissions.denied_status must be 403 or 404.');
        }

        return (new AuthorizationResponse($allowed))->withStatus($allowed ? null : $status);
    }

    public function enforcer(?Binding $binding = null): Enforcer
    {
        $model = new CasbinModel;
        $source = $this->source();
        $model->loadModelFromText($source);
        if ($binding instanceof Binding) {
            try {
                $portable = PortableModel::compile($source, $binding);
            } catch (LogicException) {
                // Wider Casbin models remain server-only and keep their native evaluation semantics.
                $portable = null;
            }

            if ($portable instanceof PortableModel && isset($model['m']['m'])) {
                $matcher = $model['m']['m'];
                $matcher->value = $portable->runtimeMatcher;
            }
        }

        $stored = $this->connection()->table('permission_models')->where('id', $this->name)->first();
        if ($stored !== null && $stored->fingerprint !== hash('sha256', $source)) {
            throw new LogicException('Casbin model changed. Rebuild permission projections and regenerate contracts before serving queries.');
        }

        $enforcer = new Enforcer($model);
        $enforcer->enableAutoSave(false);
        // Casbin counts the starting node in its hierarchy budget.
        $scoped = isset($model['g']['g']) && substr_count($model['g']['g']->value, '_') === 3;
        $enforcer->setRoleManager($scoped ? new DomainManager(9) : new RoleManager(9));
        foreach ($this->rules() as $rule) {
            $fields = $rule['fields'];
            if ($rule['ptype'] === 'p') {
                $enforcer->addNamedPolicy('p', ...$fields);
            } else {
                $enforcer->addNamedGroupingPolicy('g', ...$fields);
            }
        }

        return $enforcer;
    }

    /** @return list<array{id: string, ptype: string, fields: list<string>}> */
    public function rules(): array
    {
        $rules = [];
        foreach ($this->connection()->table('permission_rules')->where('model', $this->name)->orderBy('id')->get() as $row) {
            $fields = [];
            for ($index = 0; $index < 6; $index++) {
                $value = $row->{'v'.$index};
                if (is_string($value)) {
                    $fields[] = $value;
                }
            }

            if (! is_string($row->id) || ! is_string($row->ptype)) {
                throw new LogicException('Invalid permission rule identity.');
            }

            $rules[] = ['id' => $row->id, 'ptype' => $row->ptype, 'fields' => $fields];
        }

        return $rules;
    }

    public function addPolicy(string ...$fields): bool
    {
        return $this->write('p', array_values($fields), true);
    }

    public function removePolicy(string ...$fields): bool
    {
        return $this->write('p', array_values($fields), false);
    }

    public function addGroupingPolicy(string ...$fields): bool
    {
        return $this->write('g', array_values($fields), true);
    }

    public function removeGroupingPolicy(string ...$fields): bool
    {
        return $this->write('g', array_values($fields), false);
    }

    /** @param list<string> $fields */
    private function write(string $type, array $fields, bool $add): bool
    {
        $model = new CasbinModel;
        $source = $this->source();
        $model->loadModelFromText($source);
        $expected = count($model[$type][$type]->tokens ?? []);
        if ($expected === 0 || count($fields) !== $expected || $expected > 6) {
            throw new LogicException("Invalid {$type} rule field count for Casbin model {$this->name}.");
        }

        foreach ($fields as $field) {
            if ($field === '' || strlen($field) > 200 || str_contains($field, "\0")) {
                throw new LogicException('Permission rule fields must contain 1–200 bytes without null characters.');
            }
        }

        $fingerprint = hash('sha256', $source);
        $connection = $this->connection();

        return $connection->transaction(function () use ($connection, $fingerprint, $type, $fields, $add): bool {
            $projections = new ProjectionManager;
            $projections->lock();
            if ($connection->getSchemaBuilder()->hasTable('permission_installation')) {
                $projections->assertReady();
                foreach (array_keys(config()->array('permissions.resources')) as $class) {
                    if (is_string($class) && is_subclass_of($class, Model::class)) {
                        $binding = Binding::resource($class);
                        if ($binding->model === $this->name) {
                            $projections->validateRule(PortableModel::compile($this->source(), $binding), $type, $fields);
                        }
                    }
                }

                $connection->statement("SET LOCAL zero.permissions_write = 'on'");
            }

            $connection->table('permission_models')->insertOrIgnore(['id' => $this->name, 'fingerprint' => $fingerprint, 'revision' => 0]);
            $state = $connection->table('permission_models')->where('id', $this->name)->lockForUpdate()->first();
            if ($state === null || $state->fingerprint !== $fingerprint) {
                throw new LogicException('Casbin model fingerprint mismatch. Rebuild permissions before writing rules.');
            }

            $id = hash('sha256', json_encode([$this->name, $type, $fields], JSON_THROW_ON_ERROR));
            if ($add) {
                $row = ['id' => $id, 'model' => $this->name, 'ptype' => $type];
                foreach ($fields as $index => $field) {
                    $row['v'.$index] = $field;
                }

                $changed = $connection->table('permission_rules')->insertOrIgnore($row) > 0;
            } else {
                $changed = $connection->table('permission_rules')->where('id', $id)->delete() > 0;
            }

            if ($connection->getDriverName() === 'pgsql' && $connection->getSchemaBuilder()->hasTable('permission_installation')) {
                $connection->statement("SET LOCAL zero.permissions_write = 'off'");
            }

            if ($changed) {
                new RoleGraph($this->rules());
                $connection->table('permission_models')->where('id', $this->name)->increment('revision');
                $revision = $connection->table('permission_models')->where('id', $this->name)->value('revision');
                $connection->table('permission_events')->insert(['model' => $this->name, 'revision' => $revision]);
            }

            return $changed;
        }, 3);
    }
}
