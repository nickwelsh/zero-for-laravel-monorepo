<?php

declare(strict_types=1);

namespace ZeroForLaravel\Mutation;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

final class MutationIdentitySchema
{
    public const string TABLE = 'zero_mutation_identities';

    public static function create(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create(self::TABLE, function (Blueprint $table): void {
            $table->string('upstream_hash', 64);
            $table->string('client_group_id', 256);
            $table->string('identity_hash', 64);
            $table->primary(['upstream_hash', 'client_group_id']);
        });
    }

    public static function drop(Connection $connection): void
    {
        $connection->getSchemaBuilder()->dropIfExists(self::TABLE);
    }
}
