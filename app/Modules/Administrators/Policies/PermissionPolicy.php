<?php

namespace App\Modules\Administrators\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;
use Spatie\Permission\Models\Permission;

/**
 * Authorization for the (read-only) permission matrix.
 */
class PermissionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->hasPermissionTo('manage administrators');
    }
}
