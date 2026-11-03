<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

/** Installs transactional, database-maintained authority facts. No browser tables. */
final class ProjectionManager
{
    private const int LOCK = 1730426117;

    public function connection(): Connection
    {
        return resolve(PermissionManager::class)->connection();
    }

    public function assertConnection(Model $model): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql'
            || $this->connection()->getName() !== $model->getConnection()->getName()) {
            throw new LogicException('Maintained visibility requires one PostgreSQL 15+ connection for subjects, resources and permissions.');
        }
    }

    public function assertReady(): void
    {
        if ($this->connection()->getDriverName() !== 'pgsql'
            || ! $this->connection()->getSchemaBuilder()->hasTable('permission_installation')
            || $this->connection()->table('permission_installation')->value('fingerprint') !== $this->fingerprint()) {
            throw new LogicException('Permission projections are missing or stale. Run permissions:rebuild before serving visible queries.');
        }
    }

    /** Acquire before model-row locks and source writes. Nested managed writes reuse the transaction lock. */
    public function lock(): void
    {
        if ($this->connection()->getDriverName() === 'pgsql') {
            $this->connection()->select('SELECT pg_advisory_xact_lock(?)', [self::LOCK]);
        }
    }

    public function rebuild(): void
    {
        $db = $this->connection();
        $version = $db->getDriverName() === 'pgsql' ? $db->scalar('SHOW server_version_num') : null;
        if ((! is_string($version) && ! is_int($version)) || ! is_numeric($version) || (int) $version < 150000) {
            throw new LogicException('Permission projections require PostgreSQL 15+. Scalar Gates do not.');
        }

        $db->transaction(function () use ($db): void {
            $this->lock();
            $subjectClass = config()->string('permissions.subject.class');
            if (! is_subclass_of($subjectClass, Model::class)) {
                throw new LogicException('permissions.subject.class must be an Eloquent model.');
            }

            $subject = new $subjectClass;
            $this->validateSource($subject);
            if (! $subject instanceof Authenticatable || $subject->getAuthIdentifierName() !== $subject->getKeyName()) {
                throw new LogicException('Portable permission subjects must authenticate with their native primary key.');
            }

            $attributes = Binding::attributes(config()->get('permissions.subject.attributes'));
            $subjectValues = $this->facts($subject, $attributes, 's_');
            $subjectKey = $this->literal(config()->string('permissions.subject.prefix')).' || '.$this->quote($subject->getKeyName()).'::text';
            $this->table('permission_subjects', ['id' => 'text PRIMARY KEY', 'global_key' => "text NOT NULL DEFAULT 'global'", ...$this->types($attributes, 's_')]);
            $this->table('permission_index', ['id' => 'text PRIMARY KEY', 'model' => 'text NOT NULL', 'rule_id' => 'text NOT NULL',
                'subject_key' => 'text NOT NULL', 'effect' => 'text NOT NULL', 'global_key' => "text NOT NULL DEFAULT 'global'",
                'v0' => 'text', 'v1' => 'text', 'v2' => 'text', 'v3' => 'text', 'v4' => 'text', 'v5' => 'text', ...$this->types($attributes, 's_')]);
            $this->table('permission_installation', ['id' => 'boolean PRIMARY KEY DEFAULT true CHECK (id)', 'fingerprint' => 'text NOT NULL']);
            $db->statement('CREATE INDEX IF NOT EXISTS permission_lookup ON permission_index (subject_key, model, effect, v1, v2, v3)');
            $db->statement('CREATE INDEX IF NOT EXISTS permission_provenance ON permission_index (rule_id)');
            $this->function('zero_permissions_subjects', '', 'void', $this->replaceRows('permission_subjects', ['id' => $subjectKey, 'global_key' => "'global'", ...$subjectValues], $subject));
            $refresh = 'PERFORM zero_permissions_subjects();';
            $models = [];
            $tables = [$subject->getTable()];
            foreach (array_keys(config()->array('permissions.resources')) as $class) {
                if (! is_string($class) || ! is_subclass_of($class, Model::class)) {
                    throw new LogicException('Permission resource bindings must name Eloquent model classes.');
                }

                $binding = Binding::resource($class);
                $source = new $class;
                $this->validateSource($source);
                if (in_array($source->getTable(), $tables, true)) {
                    throw new LogicException('Each portable permission source must have one binding; subject/resource table aliases are not supported.');
                }

                $tables[] = $source->getTable();
                $facts = $this->facts($source, $binding->attributes, 'a_');
                $columns = $db->getSchemaBuilder()->getColumns($source->getTable());
                $key = collect($columns)->firstWhere('name', $source->getKeyName());
                if ($key === null || ! in_array($key['type_name'], ['int2', 'int4', 'int8', 'uuid', 'varchar', 'text'], true)) {
                    throw new LogicException('Permission resources require a native integer, UUID or text primary key.');
                }

                $this->table($binding->projectionTable(), ['resource_id' => $key['type'].' PRIMARY KEY', 'object_id' => 'text NOT NULL',
                    'domain_key' => 'text NOT NULL', 'global_key' => "text NOT NULL DEFAULT 'global'", ...$this->types($binding->attributes, 'a_')]);
                $domain = $binding->domainColumn === null ? "'__global__'" : $this->literal($binding->domainPrefix).' || '.$this->quote($binding->domainColumn).'::text';
                $function = 'zero_permissions_resource_'.substr(hash('sha256', $class), 0, 16);
                $this->function($function, '', 'void', $this->replaceRows($binding->projectionTable(), [
                    'resource_id' => $this->quote($source->getKeyName()), 'object_id' => $this->quote($source->getKeyName()).'::text',
                    'domain_key' => $domain, 'global_key' => "'global'", ...$facts,
                ], $source));
                $refresh .= 'PERFORM '.$function.'();';
                $this->sourceTriggers($source, $function, array_values(array_unique([$source->getKeyName(), ...($binding->domainColumn === null ? [] : [$binding->domainColumn]), ...array_column($binding->attributes, 'column')])));
                $portable = PortableModel::compile(resolve(PermissionManager::class)->model($binding->model)->source(), $binding);
                $models[$binding->model] = $portable;
            }

            $indexBody = 'DELETE FROM permission_index;';
            foreach ($models as $name => $portable) {
                $manager = resolve(PermissionManager::class)->model($name);
                new RoleGraph($manager->rules());
                foreach ($manager->rules() as $rule) {
                    $this->validateRule($portable, $rule['ptype'], $rule['fields']);
                }

                $db->table('permission_models')->updateOrInsert(['id' => $name], ['fingerprint' => hash('sha256', $manager->source())]);
                $indexBody .= $this->indexSql($name, $portable, array_keys($subjectValues));
            }

            $indexBody .= "IF (SELECT count(*) FROM permission_index) > 100000 THEN RAISE EXCEPTION 'Permission projection exceeds 100000 grants; reduce role fan-out'; END IF;";
            $this->function('zero_permissions_index', '', 'void', $indexBody);
            $this->function('zero_permissions_touch', '', 'void', 'WITH changed AS (UPDATE permission_models SET revision = revision + 1 RETURNING id, revision) INSERT INTO permission_events (model, revision) SELECT id, revision FROM changed;');
            $this->sourceTriggers($subject, 'zero_permissions_subjects', [$subject->getKeyName(), ...array_column($attributes, 'column')], true);
            $this->function('zero_permissions_rule_guard', '', 'trigger', 'PERFORM pg_advisory_xact_lock('.self::LOCK."); IF current_setting('zero.permissions_write', true) IS DISTINCT FROM 'on' THEN RAISE EXCEPTION 'Use managed Casbin writes, not direct permission rule writes'; END IF; RETURN NULL;");
            $db->unprepared('DROP TRIGGER IF EXISTS zero_permissions_guard ON permission_rules; CREATE TRIGGER zero_permissions_guard BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON permission_rules FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_rule_guard()');
            $this->function('zero_permissions_rules_changed', '', 'trigger', 'PERFORM zero_permissions_index(); RETURN NULL;');
            $db->unprepared('DROP TRIGGER IF EXISTS zero_permissions_changed ON permission_rules; CREATE TRIGGER zero_permissions_changed AFTER INSERT OR UPDATE OR DELETE ON permission_rules FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_rules_changed()');
            $db->getPdo()->exec('DO $rebuild$ BEGIN '.$refresh.' PERFORM zero_permissions_index(); PERFORM zero_permissions_touch(); END $rebuild$');
            $db->table('permission_installation')->updateOrInsert(['id' => true], ['fingerprint' => $this->fingerprint()]);
        }, 3);
    }

    /** @param list<string> $fields */
    public function validateRule(PortableModel $model, string $type, array $fields): void
    {
        if (count($fields) !== ($type === 'g' ? $model->roleArity : count($model->policyFields))) {
            throw new LogicException('Invalid portable rule field count.');
        }

        if ($type === 'p') {
            $values = array_combine($model->policyFields, $fields);
            if (! in_array($values['cond'], $model->conditions, true) || (isset($values['eft']) && ! in_array($values['eft'], ['allow', 'deny'], true))) {
                throw new LogicException('Unknown portable permission condition or effect.');
            }
        }
    }

    private function validateSource(Model $source): void
    {
        $this->assertConnection($source);
        $db = $this->connection();
        if ($db->getTablePrefix() !== '' || $db->scalar('SELECT current_schema()') !== 'public') {
            throw new LogicException('Permission sources require unprefixed tables in the public PostgreSQL schema.');
        }

        $indexes = $db->getSchemaBuilder()->getIndexes($source->getTable());
        $primary = array_values(array_filter($indexes, fn (array $index): bool => $index['primary']))[0]['columns'] ?? [];
        $key = $source->getKeyName();
        if ($primary !== [$key] || $source->hasGetMutator($key) || $source->hasAttributeGetMutator($key)
            || ! in_array($source->getCasts()[$key] ?? null, [null, 'int', 'integer', 'string'], true)) {
            throw new LogicException('Portable permission sources require an unmodified single-column database primary key.');
        }
    }

    private function fingerprint(): string
    {
        $sources = [];
        foreach (array_keys(config()->array('permissions.models')) as $name) {
            if (is_string($name)) {
                $sources[$name] = resolve(PermissionManager::class)->model($name)->source();
            }
        }

        return hash('sha256', json_encode([config()->get('permissions.subject'), config()->get('permissions.resources'), $sources], JSON_THROW_ON_ERROR));
    }

    /** @param array<string, string> $columns */
    private function table(string $name, array $columns): void
    {
        $sql = [];
        foreach ($columns as $column => $type) {
            $sql[] = $this->quote($column).' '.$type;
        }

        $this->connection()->statement('CREATE TABLE IF NOT EXISTS '.$this->quote($name).' ('.implode(', ', $sql).')');
        foreach ($columns as $column => $type) {
            if (! $this->connection()->getSchemaBuilder()->hasColumn($name, $column)) {
                $this->connection()->statement('ALTER TABLE '.$this->quote($name).' ADD COLUMN '.$this->quote($column).' '.$type);
            }
        }
    }

    /** @param array<string, array{column: string, type: string}> $attributes
     * @return array<string, string>
     */
    private function types(array $attributes, string $prefix): array
    {
        $types = [];
        foreach ($attributes as $name => $attribute) {
            $types[$prefix.$name] = match ($attribute['type']) {
                'boolean' => 'boolean',
                'integer' => 'bigint CHECK ('.$this->quote($prefix.$name).' BETWEEN -9007199254740991 AND 9007199254740991)',
                'id' => 'text CHECK (octet_length('.$this->quote($prefix.$name).') BETWEEN 1 AND 200)',
                default => 'text'
            };
        }

        return $types;
    }

    /** @param array<string, array{column: string, type: string}> $attributes
     * @return array<string, string>
     */
    private function facts(Model $model, array $attributes, string $prefix): array
    {
        $columns = $this->connection()->getSchemaBuilder()->getColumns($model->getTable());
        $facts = [];
        foreach ($attributes as $name => $attribute) {
            $column = collect($columns)->firstWhere('name', $attribute['column']);
            $allowed = match ($attribute['type']) {
                'boolean' => ['bool'], 'integer' => ['int2', 'int4', 'int8'], 'id' => ['int2', 'int4', 'int8', 'uuid', 'varchar', 'text'], default => ['varchar', 'text']
            };
            if ($column === null || $column['nullable'] || ! in_array($column['type_name'], $allowed, true)) {
                throw new LogicException("Permission attribute {$name} requires a non-null database column with its declared scalar type.");
            }

            $value = $this->quote($attribute['column']);
            $facts[$prefix.$name] = in_array($attribute['type'], ['id', 'string'], true) ? $value.'::text' : $value;
        }

        return $facts;
    }

    /** @param non-empty-array<string, string> $values */
    private function replaceRows(string $target, array $values, Model $source): string
    {
        $table = $this->quote($source->getTable());
        $deleted = method_exists($source, 'getDeletedAtColumn') ? $source->getDeletedAtColumn() : null;
        $where = in_array(SoftDeletes::class, class_uses_recursive($source), true) && is_string($deleted) ? ' WHERE '.$this->quote($deleted).' IS NULL' : '';

        $primary = array_key_first($values);
        $updates = [];
        $current = [];
        $incoming = [];
        foreach (array_keys($values) as $column) {
            if ($column === $primary) {
                continue;
            }

            $identifier = $this->quote($column);
            $updates[] = $identifier.' = EXCLUDED.'.$identifier;
            $current[] = $this->quote($target).'.'.$identifier;
            $incoming[] = 'EXCLUDED.'.$identifier;
        }

        return 'INSERT INTO '.$this->quote($target).' ('.implode(', ', array_map($this->quote(...), array_keys($values))).') SELECT '.implode(', ', $values).' FROM '.$table.($where === '' ? ' WHERE true' : $where)
            .' ON CONFLICT ('.$this->quote($primary).') DO UPDATE SET '.implode(', ', $updates).' WHERE ROW('.implode(', ', $current).') IS DISTINCT FROM ROW('.implode(', ', $incoming).');'
            .' DELETE FROM '.$this->quote($target).' AS authority WHERE NOT EXISTS (SELECT 1 FROM '.$table.($where === '' ? ' WHERE true' : $where).' AND ('.$values[$primary].') = authority.'.$this->quote($primary).');';
    }

    /** @param list<string> $columns */
    private function sourceTriggers(Model $source, string $refresh, array $columns, bool $subject = false): void
    {
        $table = $this->quote($source->getTable());
        $deleted = method_exists($source, 'getDeletedAtColumn') ? $source->getDeletedAtColumn() : null;
        if (in_array(SoftDeletes::class, class_uses_recursive($source), true) && is_string($deleted)) {
            $columns[] = $deleted;
        }

        $function = $refresh.'_changed';
        $this->function($function, '', 'trigger', 'PERFORM '.$refresh.'();'.($subject ? 'PERFORM zero_permissions_index();' : '').' PERFORM zero_permissions_touch(); RETURN NULL;');
        $this->function('zero_permissions_source_lock', '', 'trigger', "IF TG_OP = 'TRUNCATE' THEN RAISE EXCEPTION 'TRUNCATE is not supported on permission sources'; END IF; PERFORM pg_advisory_xact_lock(".self::LOCK.'); RETURN NULL;');
        $this->connection()->getPdo()->exec('DROP TRIGGER IF EXISTS zero_permissions_lock ON '.$table.'; CREATE TRIGGER zero_permissions_lock BEFORE INSERT OR UPDATE OR DELETE OR TRUNCATE ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_source_lock(); DROP TRIGGER IF EXISTS zero_permissions_changed ON '.$table.'; CREATE TRIGGER zero_permissions_changed AFTER INSERT OR DELETE OR UPDATE OF '.implode(', ', array_map($this->quote(...), $columns)).' ON '.$table.' FOR EACH STATEMENT EXECUTE FUNCTION '.$this->quote($function).'()');
    }

    /** @param list<string> $attributes */
    private function indexSql(string $name, PortableModel $model, array $attributes): string
    {
        $literal = $this->literal($name);
        $field = fn (string $name): string => 'p.v'.array_search($name, $model->policyFields, true);
        $subject = in_array('sub', $model->policyFields, true) ? $field('sub') : null;
        $domain = $model->scoped ? $field('dom') : "'__global__'";
        $reachable = 'true';
        if ($subject !== null) {
            $reachable = $model->roleArity === 0 ? $subject.' = s.id' : 'EXISTS (SELECT 1 FROM reach WHERE reach.subject_key = s.id AND reach.principal = '.$subject.' AND reach.domain_key = '.$domain.')';
        }

        $effect = in_array('eft', $model->policyFields, true) ? $field('eft') : "'allow'";
        $columns = implode(', ', array_map($this->quote(...), $attributes));
        $select = implode(', ', array_map(fn (string $column): string => 's.'.$this->quote($column), $attributes));

        $roleDomain = $model->roleArity === 3 ? ' AND g.v2 = reach.domain_key' : '';
        $columns = $columns === '' ? '' : ', '.$columns;
        $select = $select === '' ? '' : ', '.$select;

        return <<<SQL
            WITH RECURSIVE domains AS (
                SELECT DISTINCT {$domain} AS domain_key
                FROM permission_rules p WHERE p.model = {$literal} AND p.ptype = 'p'
            ), reach(subject_key, principal, domain_key, depth) AS (
                SELECT s.id, s.id, d.domain_key, 0
                FROM permission_subjects s CROSS JOIN domains d
                UNION
                SELECT reach.subject_key, g.v1, reach.domain_key, reach.depth + 1
                FROM reach JOIN permission_rules g
                    ON g.model = {$literal} AND g.ptype = 'g' AND g.v0 = reach.principal {$roleDomain}
                WHERE reach.depth < 8
            )
            INSERT INTO permission_index
                (id, model, rule_id, subject_key, effect, global_key, v0, v1, v2, v3, v4, v5 {$columns})
            SELECT encode(sha256(convert_to(s.id || ':' || p.id, 'UTF8')), 'hex'),
                {$literal}, p.id, s.id, {$effect}, 'global', p.v0, p.v1, p.v2, p.v3, p.v4, p.v5 {$select}
            FROM permission_subjects s CROSS JOIN permission_rules p
            WHERE p.model = {$literal} AND p.ptype = 'p' AND {$reachable};
            SQL;
    }

    private function function(string $name, string $arguments, string $returns, string $body): void
    {
        $this->connection()->getPdo()->exec('CREATE OR REPLACE FUNCTION '.$this->quote($name).'('.$arguments.') RETURNS '.$returns.' LANGUAGE plpgsql AS $permission_body$ BEGIN '.$body.' END; $permission_body$');
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $identifier)) {
            throw new LogicException('Portable permission tables and columns require simple unqualified database identifiers.');
        }

        return '"'.$identifier.'"';
    }

    private function literal(string $value): string
    {
        return $this->connection()->getPdo()->quote($value);
    }
}
