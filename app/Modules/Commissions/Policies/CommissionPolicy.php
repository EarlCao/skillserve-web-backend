<?php

namespace App\Modules\Commissions\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for the commission ledger. Reading needs "view commissions";
 * recording a remittance or writing a debt off needs "settle commissions",
 * which is deliberately separate from configuring the rates.
 *
 * Super administrators bypass every check via Gate::before.
 */
class CommissionPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('manage commissions') || $user->hasPermissionTo('view commissions');
    }

    public function settle(User $user): bool
    {
        return $user->hasPermissionTo('settle commissions');
    }
}
