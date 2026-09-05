<?php

namespace App\Modules\Audit\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

class AuditLogPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $user->hasPermissionTo('view audit logs');
    }

    public function login(User $user): bool
    {
        return $user->hasPermissionTo('view login activity');
    }

    public function security(User $user): bool
    {
        return $user->hasPermissionTo('monitor security events');
    }
}
