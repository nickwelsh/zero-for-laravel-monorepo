<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use ZeroForLaravel\Permissions\ProjectionManager;

return new class extends Migration
{
    public function up(): void
    {
        $projections = new ProjectionManager;
        if ($projections->connection()->getDriverName() === 'pgsql') {
            $projections->rebuild();
        }
    }

    public function down(): void
    {
        // Keep live grants. A package downgrade must rebuild with the old code.
    }
};
