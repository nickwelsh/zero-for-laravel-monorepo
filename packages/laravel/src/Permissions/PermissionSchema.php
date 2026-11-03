<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;

final class PermissionSchema
{
    public static function create(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        $schema->create('permission_models', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('fingerprint', 64);
            $table->bigInteger('revision')->default(0);
        });
        $schema->create('permission_rules', function (Blueprint $table): void {
            $table->string('id', 64)->primary();
            $table->string('model');
            $table->string('ptype');
            for ($index = 0; $index < 6; $index++) {
                $table->string('v'.$index)->nullable();
            }

            $table->index(['model', 'ptype']);
        });
        $schema->create('permission_events', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('model');
            $table->bigInteger('revision');
            $table->timestamp('created_at')->useCurrent();
        });
        self::createAccess($connection);
    }

    public static function createAccess(Connection $connection): void
    {
        $schema = $connection->getSchemaBuilder();
        if (! $schema->hasTable('permission_access_revision')) {
            $schema->create('permission_access_revision', function (Blueprint $table): void {
                $table->string('id')->primary();
                $table->string('revision', 36);
            });
            $connection->table('permission_access_revision')->insert(['id' => 'global', 'revision' => (string) Str::uuid()]);
            $schema->create('permission_access_checks', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('owner', 64);
                $table->uuid('generation');
                $table->string('fingerprint', 64);
                $table->string('revision', 36);
                $table->timestamp('expires_at');
                $table->index(['owner', 'generation']);
                $table->index('expires_at');
            });
            $schema->create('permission_access_results', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->uuid('generation');
                $table->uuid('binding');
                $table->string('state');
                $table->unsignedSmallInteger('status')->nullable();
            });
        }

        if ($connection->getDriverName() === 'pgsql') {
            // No sequence, policy attributes or raw event IDs are browser-visible.
            // Statement triggers cover empty model vectors and committed rebuilds too.
            $connection->unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION zero_permissions_access_changed() RETURNS trigger LANGUAGE plpgsql AS $body$
                BEGIN
                    INSERT INTO permission_access_revision (id, revision) VALUES ('global', gen_random_uuid()::text)
                    ON CONFLICT (id) DO UPDATE SET revision = EXCLUDED.revision;
                    RETURN NULL;
                END; $body$;
                DROP TRIGGER IF EXISTS zero_permissions_access_changed ON permission_models;
                CREATE TRIGGER zero_permissions_access_changed AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE
                    ON permission_models FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_access_changed();
                SQL);
            if ($schema->hasTable('permission_installation')) {
                $connection->unprepared('DROP TRIGGER IF EXISTS zero_permissions_access_changed ON permission_installation; CREATE TRIGGER zero_permissions_access_changed AFTER INSERT OR UPDATE OR DELETE OR TRUNCATE ON permission_installation FOR EACH STATEMENT EXECUTE FUNCTION zero_permissions_access_changed()');
            }
        }
    }

    public static function dropAccess(Connection $connection): void
    {
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared('DROP FUNCTION IF EXISTS zero_permissions_access_changed() CASCADE');
        }

        foreach (['permission_access_results', 'permission_access_checks', 'permission_access_revision'] as $table) {
            $connection->getSchemaBuilder()->dropIfExists($table);
        }
    }
}
