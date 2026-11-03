<?php

declare(strict_types=1);

use App\Models\TodoList;
use App\Permissions\ListPermissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use ZeroForLaravel\Permissions\ProjectionManager;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            (new ProjectionManager)->lock();
            foreach (TodoList::query()->cursor() as $list) {
                ListPermissions::grantOwner($list);
                foreach (DB::table('list_memberships')->where('list_id', $list->id)->pluck('user_id') as $userId) {
                    if ((int) $userId !== $list->user_id) {
                        ListPermissions::grantReader($list, (int) $userId);
                    }
                }
            }

            if (DB::connection()->getDriverName() === 'pgsql') {
                (new ProjectionManager)->rebuild();
            }
        });
    }

    public function down(): void
    {
        // Grants are now authoritative: rolling back must not revoke live access or
        // resurrect stale memberships. The schema migration owns table removal.
    }
};
