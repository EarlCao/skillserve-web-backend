<?php

namespace App\Modules\Administrators\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for administrator-management actions.
 *
 * Super administrators bypass every check via Gate::before in
 * AppServiceProvider; every other role needs the "manage administrators"
 * permission.
 */
class AdministratorPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function view(User $user, User $administrator): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function update(User $user, User $administrator): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function updateStatus(User $user, User $administrator): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }
}
