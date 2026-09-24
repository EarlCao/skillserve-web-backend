<?php

namespace App\Modules\Commissions\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

/** Dispatched after an administrator writes off an outstanding commission. */
class CommissionWaived
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
