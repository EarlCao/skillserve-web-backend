<?php

namespace App\Modules\Administrators\Policies;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
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

    public function resetPassword(User $user, User $administrator): bool
    {
        // Only a super administrator may reset a super administrator's
        // password (super-admins bypass this gate via Gate::before, which is
        // exactly who should be allowed).
        if ($administrator->hasRole(SystemRole::SUPER_ADMIN) && ! $user->hasRole(SystemRole::SUPER_ADMIN)) {
            return false;
        }

        // Regular administrators change their own password through the
        // self-service change-password flow (which verifies the current
        // password); only super-admins may reset their own here.
        if ($user->is($administrator) && ! $user->hasRole(SystemRole::SUPER_ADMIN)) {
            return false;
        }

        return $user->hasPermissionTo('manage administrators');
    }
}
