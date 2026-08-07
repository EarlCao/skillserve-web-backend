<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Dispatched after a role's permission set is synced from the matrix.
 */
class RolePermissionsSynced
{
    /**
     * @param  array<int, string>  $permissions
     */
    public function __construct(
        public readonly Role $role,
        public readonly User $actor,
        public readonly array $permissions,
    ) {}
}
