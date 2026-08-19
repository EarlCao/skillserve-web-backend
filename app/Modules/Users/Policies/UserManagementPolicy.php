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
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage users') || $user->hasPermissionTo($permission);
    }

    /**
     * Whether the user may manage platform user accounts.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('manage users');
    }

    public function view(User $user): bool
    {
        return $this->allows($user, 'view users');
    }

    public function edit(User $user): bool
    {
        return $this->allows($user, 'edit users');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'delete users');
    }

    public function suspend(User $user): bool
    {
        return $this->allows($user, 'suspend users');
    }

    public function activate(User $user): bool
    {
        return $this->allows($user, 'activate users');
    }

    public function ban(User $user): bool
    {
        return $this->allows($user, 'ban users');
    }
}
