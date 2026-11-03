<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use ZeroForLaravel\Permissions\PermissionSchema;

return new class extends Migration
{
    public function up(): void
    {
        PermissionSchema::createAccess(DB::connection());
        DB::connection()->getSchemaBuilder()->dropIfExists('permission_access_watches');
    }

    public function down(): void
    {
        PermissionSchema::dropAccess(DB::connection());
    }
};
