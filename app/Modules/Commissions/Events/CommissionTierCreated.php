<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;

/** Dispatched after an administrator configures a new commission band. */
class CommissionTierCreated
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public readonly CommissionTier $tier,
        public readonly User $actor,
        public readonly array $data,
    ) {}
}
