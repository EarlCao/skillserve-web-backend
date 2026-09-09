<?php

namespace App\Modules\ClientMarketplace\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Actions\BaseAction;

final class CancelClientBookingAction extends BaseAction
{
    /**
     * This changes booking state only. No external payment or refund provider
     * is called; unpaid remains unpaid and paid state remains unchanged.
     */
    public function handle(Booking $booking, User $client, ?string $reason): Booking
    {
        $booking->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
            'cancelled_by' => $client->id,
        ]);

        return $booking->fresh();
    }
}
