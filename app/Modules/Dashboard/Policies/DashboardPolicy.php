<?php

namespace App\Modules\Dashboard\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

class DashboardPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view dashboard');
    }
}
