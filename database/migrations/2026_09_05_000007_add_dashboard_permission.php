<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            ['name' => 'view dashboard', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ], ['name', 'guard_name'], ['updated_at']);

        $permissionId = DB::table('permissions')
            ->where('name', 'view dashboard')
            ->where('guard_name', 'web')
            ->value('id');

        $roleIds = DB::table('roles')
            ->whereIn('name', ['super-admin', 'admin'])
            ->where('guard_name', 'web')
            ->pluck('id');

        DB::table('role_has_permissions')->insertOrIgnore($roleIds->map(fn ($roleId): array => [
            'permission_id' => $permissionId,
            'role_id' => $roleId,
        ])->all());
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'view dashboard')->where('guard_name', 'web')->delete();
    }
};
