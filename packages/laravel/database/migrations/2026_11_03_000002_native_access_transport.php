<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use ZeroForLaravel\Permissions\PermissionManager;
use ZeroForLaravel\Permissions\PermissionSchema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = resolve(PermissionManager::class)->connection();
        PermissionSchema::createAccess($connection);
        // Ephemeral SSE registrations are not grants or application data.
        $connection->getSchemaBuilder()->dropIfExists('permission_access_watches');
    }

    public function down(): void
    {
        PermissionSchema::dropAccess(resolve(PermissionManager::class)->connection());
    }
};
