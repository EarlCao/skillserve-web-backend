<?php

namespace App\Modules\Commissions\Events;

use App\Modules\Commissions\Models\CommissionRemittance;

/**
 * A QR payment PayMongo reported that SkillServe could not settle on its own
 * (wrong amount, or paid after it was rejected): an administrator must check
 * it and confirm or reject it.
 */
class CommissionRemittanceFlagged
{
    public function __construct(
        public readonly CommissionRemittance $remittance,
    ) {}
}
