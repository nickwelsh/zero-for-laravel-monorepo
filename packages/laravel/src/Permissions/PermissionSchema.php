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
    }
}
