<?php

namespace App\Modules\Bookings\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Actions\BaseAction;

final class CancelBookingAction extends BaseAction
{
    public function handle(Booking $booking, User $actor, ?string $reason = null): Booking
    {
        $booking->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => $actor->id,
        ]);

        return $booking->fresh();
    }
}
