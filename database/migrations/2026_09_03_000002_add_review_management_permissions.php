<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const PERMISSIONS = [
        'manage reviews',
        'view reviews',
        'edit reviews',
        'delete reviews',
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
