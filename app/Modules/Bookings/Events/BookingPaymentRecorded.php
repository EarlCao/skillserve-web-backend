<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

/**
 * A settlement was recorded by hand: `paid` when the booking was marked paid,
 * `refunded` when a full or partial refund was recorded.
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
