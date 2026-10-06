<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data only. The seeder's permission list missed the seven granular service
 * permissions the migrations create, so a super-admin seeded from it lacked
 * them. Grants the super-admin role every permission; adds, never removes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')
            ->where('name', 'super-admin')
            ->where('guard_name', 'web')
            ->first(['id']);

        if (! $role) {
            return;
        }

        $rows = DB::table('permissions')
            ->where('guard_name', 'web')
            ->pluck('id')
            ->map(fn ($id): array => ['permission_id' => $id, 'role_id' => $role->id])
            ->all();

        if ($rows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($rows);
        }

        Cache::forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        // Super-admin permissions are intentionally not removed on rollback.
    }
};
