<?php

namespace App\Modules\ClientMarketplace\Listeners;

use App\Modules\Bookings\Events\BookingPaymentRecorded;
use App\Modules\Bookings\Events\BookingRefundChanged;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Events\PaymentProofReviewed;
use App\Modules\Bookings\Events\PaymentProofSubmitted;
use App\Modules\Bookings\Models\Booking;
use App\Modules\ClientMarketplace\Events\ClientBookingChanged;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Tells both parties' open apps that a booking changed, once the change is
 * committed, so a booking screen never reloads before the data is there.
 */
class PushBookingChange
{
    public function handle(BookingStatusChanged|BookingPaymentRecorded|PaymentProofSubmitted|PaymentProofReviewed|BookingRefundChanged $event): void
    {
        $booking = match (true) {
            $event instanceof PaymentProofSubmitted, $event instanceof PaymentProofReviewed => $event->proof->booking,
            $event instanceof BookingRefundChanged => $event->refund->booking,
            default => $event->booking,
        };

        if (! $booking instanceof Booking) {
            return;
        }

        $parties = array_filter([
            (int) $booking->client_id,
            (int) ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'),
        ]);
        $bookingId = (int) $booking->id;

        DB::afterCommit(function () use ($parties, $bookingId): void {
            foreach ($parties as $userId) {
                try {
                    event(new ClientBookingChanged($userId, $bookingId));
                } catch (Throwable $exception) {
                    Log::warning('A booking change could not be pushed live.', ['booking_id' => $bookingId, 'error' => $exception->getMessage()]);
                }
            }
        });
    }
}
