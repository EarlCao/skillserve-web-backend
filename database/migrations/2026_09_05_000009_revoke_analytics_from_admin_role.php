<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Reports & Analytics exposes cross-module PII and is reserved for the
     * super-admin role. The initial analytics permission migration granted it
     * to the "admin" role too — remove that grant on already-migrated
     * databases so least privilege is restored everywhere.
     */
    public function up(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('name', ['view analytics', 'export analytics'])
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($permissionIds->isEmpty()) {
            return;
        }

        $adminRoleId = DB::table('roles')
            ->where('name', 'admin')
            ->where('guard_name', 'web')
            ->value('id');

        if ($adminRoleId) {
            DB::table('role_has_permissions')
                ->where('role_id', $adminRoleId)
                ->whereIn('permission_id', $permissionIds)
                ->delete();
        }
    }

    public function down(): void
    {
        // Re-granting is intentionally not performed on rollback.
    }
};
