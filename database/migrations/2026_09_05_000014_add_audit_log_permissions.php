<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view audit logs',
        'view login activity',
        'monitor security events',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->upsert([
                ['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ], ['name', 'guard_name'], ['updated_at']);
        }

        $roles = DB::table('roles')->whereIn('name', ['super-admin', 'admin'])->where('guard_name', 'web')->pluck('id');
        $permissions = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->pluck('id');

        DB::table('role_has_permissions')->insertOrIgnore(
            $roles->flatMap(fn ($roleId) => $permissions->map(fn ($permissionId): array => [
                'permission_id' => $permissionId,
                'role_id' => $roleId,
            ]))->all(),
        );
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
