<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ZeroForLaravel\Permissions\PermissionSchema;

return new class extends Migration
{
    public function up(): void
    {
        PermissionSchema::create(DB::connection());
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_access_watches');
        Schema::dropIfExists('permission_events');
        Schema::dropIfExists('permission_rules');
        Schema::dropIfExists('permission_models');
    }
};
