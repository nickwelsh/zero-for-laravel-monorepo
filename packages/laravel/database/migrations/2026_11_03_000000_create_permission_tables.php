<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use ZeroForLaravel\Permissions\PermissionManager;
use ZeroForLaravel\Permissions\PermissionSchema;

return new class extends Migration
{
    public function up(): void
    {
        PermissionSchema::create(resolve(PermissionManager::class)->connection());
    }

    public function down(): void
    {
        $schema = resolve(PermissionManager::class)->connection()->getSchemaBuilder();
        $schema->dropIfExists('permission_access_watches');
        $schema->dropIfExists('permission_events');
        $schema->dropIfExists('permission_rules');
        $schema->dropIfExists('permission_models');
    }
};
