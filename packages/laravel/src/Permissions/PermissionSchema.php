<?php

declare(strict_types=1);

namespace ZeroForLaravel\Permissions;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

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
        if ($connection->getDriverName() === 'pgsql') {
            $connection->unprepared('DROP FUNCTION IF EXISTS zero_permissions_access_changed() CASCADE');
        }

        $schema->dropIfExists('permission_access_revision');
        if (! $schema->hasTable('permission_access_checks')) {
            $schema->create('permission_access_checks', function (Blueprint $table): void {
                $table->string('id', 64)->primary();
                $table->string('owner', 64);
                $table->uuid('generation');
                $table->string('fingerprint', 64);
                $table->string('global_key')->default('global');
                $table->json('dependencies')->nullable();
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

        // A published legacy column cannot be dropped without cascading into its
        // publication. Leave it nullable and unused; new installs never create it.
        if ($schema->hasColumn('permission_access_checks', 'revision')) {
            if ($connection->getDriverName() === 'pgsql') {
                $connection->statement('ALTER TABLE permission_access_checks ALTER COLUMN revision DROP NOT NULL');
            } else {
                $schema->table('permission_access_checks', fn (Blueprint $table) => $table->string('revision', 36)->nullable()->change());
            }
        }

        if (! $schema->hasColumn('permission_access_checks', 'dependencies')) {
            $schema->table('permission_access_checks', function (Blueprint $table): void {
                $table->string('global_key')->default('global');
                $table->json('dependencies')->nullable();
            });
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
