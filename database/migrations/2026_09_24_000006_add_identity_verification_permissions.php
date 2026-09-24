<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Identity Verification permissions, granted to super-admin only. Reviewing
 * National ID submissions means handling sensitive personal data, so ordinary
 * administrators receive these through a custom role rather than by default.
 *
 * Data impact: inserts permission rows and one role-permission link per
 * permission. Rollback deletes them, cascading the role links.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view identity verifications',
        'verify identities',
        'reject identities',
    ];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]))
            ->all();

        if ($role = Role::query()->where('name', 'super-admin')->first()) {
            $role->givePermissionTo($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::query()->where('name', $permission)->delete();
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
