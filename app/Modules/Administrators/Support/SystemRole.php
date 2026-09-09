<?php

namespace App\Modules\Administrators\Support;

use App\Models\User;

/**
 * Well-known role names the Administrator Management module must protect.
 */
final class SystemRole
{
    /**
     * The bootstrap role. It bypasses every gate (see AppServiceProvider) and
     * must never be deleted, renamed, or lose its permissions.
     */
    public const SUPER_ADMIN = 'super-admin';

    /** @var array<int, string> */
    public const PROTECTED_PERMISSIONS = [
        'manage administrators',
    ];

    public static function mayGrantProtectedPermissions(User $user): bool
    {
        return $user->hasRole(self::SUPER_ADMIN);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public static function containsProtectedPermissions(array $permissions): bool
    {
        return array_intersect(self::PROTECTED_PERMISSIONS, $permissions) !== [];
    }
}
