<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Commissions\Models\CommissionTier;

/** Dispatched after a commission band's range, rate or state changes. */
class CommissionTierUpdated
{
    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function __construct(
        public readonly CommissionTier $tier,
        public readonly User $actor,
        public readonly array $before,
        public readonly array $after,
    ) {}
}
