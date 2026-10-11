<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionRemittance;

/** Dispatched after an administrator rejects a provider's GCash commission payment. */
class CommissionRemittanceRejected
{
    public function __construct(
        public readonly CommissionRemittance $remittance,
        public readonly User $actor,
    ) {}
}
