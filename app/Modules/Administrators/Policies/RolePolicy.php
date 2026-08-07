<?php

namespace App\Modules\Administrators\Policies;

use App\Models\User;
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

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function update(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function delete(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function syncPermissions(User $user, Role $role): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }
}
