<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

class BookingCancelled
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly ?string $reason = null,
    ) {}
}
