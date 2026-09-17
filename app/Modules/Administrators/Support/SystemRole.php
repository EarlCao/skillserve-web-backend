<?php

namespace App\Modules\Administrators\Support;

use App\Models\User;
use App\Shared\Enums\AccountRole;
use App\Shared\Exceptions\ApiException;
use Spatie\Permission\Models\Role;

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

    /**
     * Roles 1–4 classify accounts (users.role_id) and cannot be renamed or deleted.
     */
    public static function isFixed(Role $role): bool
    {
        return AccountRole::tryFrom((int) $role->getKey()) !== null;
    }

    /**
     * Provider and customer are account types, not staff roles: they cannot be
     * managed on the Roles page or given to administrators.
     */
    public static function isAccountType(Role $role): bool
    {
        return in_array((int) $role->getKey(), AccountRole::accountTypeIds(), true);
    }

    /**
     * @throws ApiException when the role is a provider/customer account type.
     */
    public static function assertStaffRole(Role $role): void
    {
        if (self::isAccountType($role)) {
            throw new ApiException(
                'Provider and customer roles are account types and cannot be managed here.',
                422,
                errors: ['role' => ['Provider and customer roles are account types and cannot be managed here.']],
            );
        }
    }

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
