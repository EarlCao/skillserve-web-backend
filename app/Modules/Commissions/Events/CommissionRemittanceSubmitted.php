<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionRemittance;

/** Dispatched after a provider submits a GCash commission payment for review. */
class CommissionRemittanceSubmitted
{
    public function __construct(
        public readonly CommissionRemittance $remittance,
        public readonly User $actor,
    ) {}
}
