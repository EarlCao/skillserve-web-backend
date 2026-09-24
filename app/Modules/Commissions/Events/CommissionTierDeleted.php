<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;

/** Dispatched after a commission band is retired. */
class CommissionTierDeleted
{
    public function __construct(
        public readonly CommissionTier $tier,
        public readonly User $actor,
    ) {}
}
