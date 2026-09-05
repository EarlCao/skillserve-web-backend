<?php

namespace App\Modules\Analytics\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for the Reports & Analytics module.
 *
 * Reports are read-only aggregates over the other modules' data, so no
 * model policy is required — these named gates gate the two actions.
 */
class AnalyticsPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view analytics');
    }

    public function export(User $user): bool
    {
        return $user->hasPermissionTo('export analytics');
    }
}
