<?php

namespace App\Modules\Administrators\Policies;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Policies\BasePolicy;
use Spatie\Permission\Models\Role;

/**
 * Authorization for role-management actions (CRUD + permission sync).
 */
class RolePolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function view(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function create(User $user, array $permissions = []): bool
    {
        return $user->hasPermissionTo('manage administrators')
            && (SystemRole::mayGrantProtectedPermissions($user)
                || ! SystemRole::containsProtectedPermissions($permissions));
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function syncPermissions(User $user, Role $role, array $permissions = []): bool
    {
        return $user->hasPermissionTo('manage administrators')
            && (SystemRole::mayGrantProtectedPermissions($user)
                || ! SystemRole::containsProtectedPermissions($permissions));
    }
}
