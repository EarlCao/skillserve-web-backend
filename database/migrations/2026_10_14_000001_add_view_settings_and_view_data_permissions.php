<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Read-only levels for System Settings and Data Management, which had only
 * full-control permissions. Data only: two permission rows, granted to the
 * super administrator and to every role that already manages the area (full
 * control includes view). Rollback removes the two permissions and their
 * grants.
 */
return new class extends Migration
{
    private const GRANTS = [
        'view settings' => ['manage settings'],
        'view data' => ['manage data', 'export system data', 'archive records', 'restore archived records', 'restore deleted records', 'manage deleted records'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $name => $impliedBy) {
            DB::table('permissions')->upsert(
                [['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()]],
                ['name', 'guard_name'],
                ['updated_at'],
            );

            $permissionId = DB::table('permissions')->where('name', $name)->where('guard_name', 'web')->value('id');

            $roleIds = DB::table('roles')->where('name', 'super-admin')->where('guard_name', 'web')->pluck('id')
                ->merge(DB::table('role_has_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
                    ->whereIn('permissions.name', $impliedBy)
                    ->pluck('role_has_permissions.role_id'))
                ->unique();

            DB::table('role_has_permissions')->insertOrIgnore($roleIds->map(fn ($roleId): array => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ])->values()->all());
        }

        app()['cache']->forget(config('permission.cache.key'));
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', array_keys(self::GRANTS))->where('guard_name', 'web')->delete();
        app()['cache']->forget(config('permission.cache.key'));
    }
};
