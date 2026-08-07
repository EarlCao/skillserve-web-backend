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
            'manage providers',
            'manage services',
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
    }
}
