<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use ZeroForLaravel\Metadata\ModelDiscovery;
use ZeroForLaravel\Mutation\MutationIdentitySchema;

return new class extends Migration
{
    public function up(): void
    {
        MutationIdentitySchema::create($this->connection());
    }

    public function down(): void
    {
        MutationIdentitySchema::drop($this->connection());
    }

    private function connection(): Connection
    {
        $class = resolve(ModelDiscovery::class)->registered()[0] ?? null;
        $model = is_string($class) ? new $class : null;

        return $model instanceof Model ? $model->getConnection() : DB::connection();
    }
};
