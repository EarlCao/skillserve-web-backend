<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionRemittance;

/** Dispatched after an administrator confirms a provider's GCash commission payment and the bookings it covered are settled. */
class CommissionRemittanceConfirmed
{
    public function __construct(
        public readonly CommissionRemittance $remittance,
        public readonly User $actor,
    ) {}
}
