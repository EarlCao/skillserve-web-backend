<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

class BookingDisputeManaged
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly string $action,
        public readonly ?string $resolution = null,
    ) {}
}
