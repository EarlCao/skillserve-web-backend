<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'view administrators', 'create administrators', 'edit administrators',
        'view users', 'edit users', 'delete users', 'suspend users', 'activate users', 'ban users',
        'view providers', 'edit providers', 'delete providers', 'suspend providers', 'activate providers', 'verify providers', 'reject providers',
        'view service categories', 'create service categories', 'edit service categories', 'delete service categories',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $name) {
            DB::table('permissions')->upsert([
                ['name' => $name, 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ], ['name', 'guard_name'], ['updated_at']);
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
