<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds the Role-Based Access Control catalog and the bootstrap
 * super-admin account used for the first login.
 *
 * Permission names use the "manage <entity>" convention and are meant to
 * grow with each phase — modules create their own permissions here later.
 */
class RolePermissionSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = [
            'manage administrators',
            'view administrators',
            'create administrators',
            'edit administrators',
            'manage users',
            'view users',
            'edit users',
            'delete users',
            'suspend users',
            'activate users',
            'ban users',
            'manage providers',
            'manage services',
            'manage service categories',
            'view service categories',
            'create service categories',
            'edit service categories',
            'delete service categories',
            'manage bookings',
            'view reports',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions($permissions);

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions(['view reports']);

        // Bootstrap account (override via ADMIN_EMAIL / ADMIN_PASSWORD in .env).
        $adminUser = User::query()->firstOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@skillserve.test')],
            [
                'name' => 'System Administrator',
                'password' => Hash::make(env('ADMIN_PASSWORD', 'SkillServe#2026')),
            ],
        );

        if (! $adminUser->hasRole('super-admin')) {
            $adminUser->assignRole('super-admin');
        }

        // Exactly one ordinary administrator (override via SYSTEM_ADMIN_EMAIL /
        // SYSTEM_ADMIN_PASSWORD in .env). Role-bearing accounts are managed in
        // the Administrator Management module, never in User Management.
        $systemAdmin = User::query()->firstOrCreate(
            ['email' => env('SYSTEM_ADMIN_EMAIL', 'system@skillserve.test')],
            [
                'name' => 'System Admin',
                'password' => Hash::make(env('SYSTEM_ADMIN_PASSWORD', 'SkillServe#2026')),
            ],
        );

        if (! $systemAdmin->hasRole('admin')) {
            $systemAdmin->assignRole('admin');
        }
    }
}
