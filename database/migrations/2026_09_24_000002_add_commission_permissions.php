<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Commission Management permissions, granted to super-admin only. Ordinary
 * administrators receive them through a custom role, the same way every other
 * money-touching permission is handled.
 *
 * `settle commissions` is created here alongside the other two so the module
 * has one permission catalog; it grants nothing until the settlement
 * endpoints exist.
 *
 * Data impact: inserts permission rows and one role-permission link per
 * permission. Rollback deletes the permissions, which cascades the role links.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'view commissions',
        'manage commissions',
        'settle commissions',
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
