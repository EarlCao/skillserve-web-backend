<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use Carbon\CarbonInterface;

class BookingRescheduled
{
    public function __construct(
        public readonly Booking $booking,
        public readonly User $actor,
        public readonly ?CarbonInterface $previousStart,
        public readonly ?CarbonInterface $previousEnd,
    ) {}
}
