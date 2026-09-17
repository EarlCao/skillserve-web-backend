<?php

namespace App\Shared\Enums;

/**
 * The fixed rows of the `roles` table that classify every account
 * (`users.role_id`). Ids are part of the data contract and never change.
 *
 * Staff roles (super-admin, admin and any custom staff role, id 5+) also carry
 * permissions through the Spatie role assignment; provider and customer
 * accounts are identified by `users.role_id` alone.
 */
enum AccountRole: int
{
    case SuperAdmin = 1;
    case Admin = 2;
    case Provider = 3;
    case Customer = 4;

    public function roleName(): string
    {
        return match ($this) {
            self::SuperAdmin => 'super-admin',
            self::Admin => 'admin',
            self::Provider => 'provider',
            self::Customer => 'customer',
        };
    }

    /**
     * Mobile account roles — never assignable to administrators and without permissions.
     *
     * @return array<int, int>
     */
    public static function accountTypeIds(): array
    {
        return [self::Provider->value, self::Customer->value];
    }

    /**
     * Legacy `user_type` value for a role id: staff roles are "admin".
     */
    public static function userTypeFor(?int $roleId): string
    {
        return match ($roleId) {
            self::Provider->value => 'provider',
            self::Customer->value, null => 'customer',
            default => 'admin',
        };
    }

    /**
     * Role id for a legacy `user_type` value.
     */
    public static function idForUserType(string $userType): int
    {
        return match ($userType) {
            'provider' => self::Provider->value,
            'admin' => self::Admin->value,
            default => self::Customer->value,
        };
    }
}
