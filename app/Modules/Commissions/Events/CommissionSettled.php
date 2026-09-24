<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Commissions\Models\CommissionSettlement;

/** Dispatched after a provider's commission remittance is recorded. */
class CommissionSettled
{
    public function __construct(
        public readonly Booking $booking,
        public readonly CommissionSettlement $settlement,
        public readonly User $actor,
    ) {}
}
