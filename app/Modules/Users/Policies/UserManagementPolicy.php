<?php

namespace App\Modules\Users\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for user-management actions.
 *
 * The User model policy slot is already claimed by the Administrators module
 * (AdministratorPolicy), so this module exposes a single named ability —
 * "manage users" — that is wired through Gate::define in AppServiceProvider.
 * Super administrators bypass every check via Gate::before.
 */
class UserManagementPolicy extends BasePolicy
{
    /**
     * Whether the user may manage platform user accounts.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('manage users');
    }
}
