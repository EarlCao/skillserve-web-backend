<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Existing installations need newly introduced permissions assigned to the
     * immutable super-admin role as soon as the permission catalog changes.
     */
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name' => 'manage reviews',
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name', 'guard_name'], ['updated_at']);

        $role = DB::table('roles')
            ->where('name', 'super-admin')
            ->where('guard_name', 'web')
            ->first(['id']);

        if (! $role) {
            return;
        }

        $permissionIds = DB::table('permissions')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->map(fn ($id): array => [
                'permission_id' => $id,
                'role_id' => $role->id,
            ])
            ->all();

        if ($permissionIds !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($permissionIds);
        }
    }

    public function down(): void
    {
        // Super-admin permissions are intentionally not removed on rollback.
    }
};
