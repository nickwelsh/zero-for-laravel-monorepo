<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use ZeroForLaravel\Permissions\PermissionManager;
use ZeroForLaravel\Permissions\PermissionSchema;
use ZeroForLaravel\Permissions\ProjectionManager;

return new class extends Migration
{
    public function up(): void
    {
        $connection = resolve(PermissionManager::class)->connection();
        PermissionSchema::createAccess($connection);
        if ($connection->getDriverName() === 'pgsql' && $connection->getSchemaBuilder()->hasTable('permission_installation')) {
            // Upgrade the installed index function before publication synchronization.
            (new ProjectionManager)->rebuild();
        }
    }

    public function down(): void
    {
        PermissionSchema::dropAccess(resolve(PermissionManager::class)->connection());
    }
};
