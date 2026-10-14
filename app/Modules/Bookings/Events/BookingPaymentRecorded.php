<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

/**
 * A settlement was recorded: `paid` when the booking became fully paid,
 * `partially_paid` when a downpayment was confirmed, `refunded` when a full
 * or partial refund was recorded. [amount] is what this step added.
 */
class BookingPaymentRecorded
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly string $action,
        public readonly string $oldPaymentStatus,
        public readonly ?float $amount = null,
    ) {}
}
