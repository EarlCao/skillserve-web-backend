<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

class BookingStatusChanged
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly string $oldStatus,
        public readonly string $newStatus,
        // Set when a dispute decision changed the status: the parties get the
        // dispute notice instead of the ordinary status message.
        public readonly bool $fromDispute = false,
    ) {}
}
