<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use LogicException;
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
        if (app()->environment('production')) {
            $adminPassword = (string) env('ADMIN_PASSWORD', '');
            $systemPassword = (string) env('SYSTEM_ADMIN_PASSWORD', '');

            if ($adminPassword === '' || $systemPassword === ''
                || $adminPassword === 'SkillServe#2026'
                || $systemPassword === 'SkillServe#2026') {
                throw new LogicException('Production seeding requires non-default ADMIN_PASSWORD and SYSTEM_ADMIN_PASSWORD values.');
            }
        }

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
            'view providers',
            'edit providers',
            'delete providers',
            'suspend providers',
            'activate providers',
            'verify providers',
            'reject providers',
            'manage services',
            'manage service categories',
            'view service categories',
            'create service categories',
            'edit service categories',
            'delete service categories',
            'manage bookings',
            'view bookings',
            'cancel bookings',
            'manage booking disputes',
            'view reviews',
            'manage reviews',
            'edit reviews',
            'delete reviews',
            'view reports',
            'manage reports',
            'investigate reports',
            'resolve reports',
            'manage moderation',
            'view notifications',
            'send announcements',
            'target notifications',
            'schedule announcements',
            'view dashboard',
            'view analytics',
            'export analytics',
            'view provider recognition',
            'manage provider badges',
            'assign provider badges',
            'manage featured providers',
            'view top rated providers',
            'view audit logs',
            'view login activity',
            'monitor security events',
            'manage settings',
            'manage data',
            'export system data',
            'archive records',
            'restore archived records',
            'restore deleted records',
            'manage deleted records',
            'view support',
            'manage support',
            'assign support tickets',
            'respond to support tickets',
            'resolve support tickets',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        $superAdmin = Role::firstOrCreate(['name' => 'super-admin']);
        $superAdmin->syncPermissions($permissions);

        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->syncPermissions([
            'view reports', 'view dashboard',
            'view provider recognition', 'manage provider badges',
            'assign provider badges', 'manage featured providers', 'view top rated providers',
            'view audit logs', 'view login activity', 'monitor security events',
        ]);

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

        // Skip the system admin in admin-only mode (SEED_MODE=admin-only).
        if (env('SEED_MODE', 'demo') !== 'admin-only') {
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
}
