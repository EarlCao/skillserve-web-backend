<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view notifications',
        'send announcements',
        'target notifications',
        'schedule announcements',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->upsert([
                ['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ], ['name', 'guard_name'], ['updated_at']);
        }

        $role = DB::table('roles')->where('name', 'super-admin')->where('guard_name', 'web')->first('id');

        if ($role) {
            $permissionIds = DB::table('permissions')->whereIn('name', self::PERMISSIONS)->pluck('id');
            DB::table('role_has_permissions')->insertOrIgnore($permissionIds->map(fn ($id): array => [
                'permission_id' => $id,
                'role_id' => $role->id,
            ])->all());
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
