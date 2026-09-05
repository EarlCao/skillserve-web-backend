<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        $permissions = DB::table('permissions')
            ->whereIn('name', [
                'view provider recognition',
                'manage provider badges',
                'assign provider badges',
                'manage featured providers',
                'view top rated providers',
            ])
            ->where('guard_name', 'web')
            ->pluck('id');

        if ($adminRole) {
            DB::table('role_has_permissions')->insertOrIgnore($permissions->map(fn ($permissionId): array => [
                'permission_id' => $permissionId,
                'role_id' => $adminRole,
            ])->all());
        }
    }

    public function down(): void
    {
        $adminRole = DB::table('roles')->where('name', 'admin')->where('guard_name', 'web')->value('id');
        $permissions = DB::table('permissions')->whereIn('name', [
            'view provider recognition',
            'manage provider badges',
            'assign provider badges',
            'manage featured providers',
            'view top rated providers',
        ])->pluck('id');

        if ($adminRole) {
            DB::table('role_has_permissions')->where('role_id', $adminRole)->whereIn('permission_id', $permissions)->delete();
        }
    }
};
