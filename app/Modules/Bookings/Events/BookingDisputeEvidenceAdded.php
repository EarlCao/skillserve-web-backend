<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

/** A party to an open dispute attached a photo as evidence. */
class BookingDisputeEvidenceAdded
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
    ) {}
}
