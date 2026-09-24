<?php

namespace App\Modules\Commissions\Policies;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for commission-tier configuration.
 *
 * Super administrators bypass every check via Gate::before in
 * AppServiceProvider. Reading the configuration needs "view commissions";
 * changing the rates that decide SkillServe's revenue needs the stronger
 * "manage commissions".
 */
class CommissionTierPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage commissions') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view commissions');
    }

    public function view(User $user, CommissionTier $tier): bool
    {
        return $this->allows($user, 'view commissions');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('manage commissions');
    }

    public function update(User $user, CommissionTier $tier): bool
    {
        return $user->hasPermissionTo('manage commissions');
    }

    public function delete(User $user, CommissionTier $tier): bool
    {
        return $user->hasPermissionTo('manage commissions');
    }
}
