<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingPaymentRecorded;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * A recorded payment or refund concerns both sides of the booking — the
 * customer's receipt and the provider's earnings — so both hear about it,
 * except whoever recorded it.
 */
class NotifyPaymentParticipants
{
    public function handle(BookingPaymentRecorded $event): void
    {
        $booking = $event->booking;
        $booking->loadMissing('service:id,title');
        $service = $booking->service?->title;
        $name = $service ? "\u{201C}{$service}\u{201D}" : 'your booking';
        $amount = number_format((float) $event->amount, 2);

        $notification = $event->action === 'refunded'
            ? new BookingStatusNotification(
                $booking, 'refunded', 'Refund recorded',
                "A refund of {$booking->currency} {$amount} was recorded for {$name}.",
                $booking->refund_reason,
            )
            : new BookingStatusNotification(
                $booking, 'paid', 'Payment recorded',
                "Payment of {$booking->currency} {$amount} for {$name} was recorded. Thank you!",
            );

        // Resolved from ids, as in NotifyBookingParticipants.
        $recipients = [
            User::query()->find($booking->client_id),
            User::query()->find(ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id')),
        ];

        foreach ($recipients as $recipient) {
            if ($recipient && ! $recipient->is($event->actor)) {
                $recipient->notify($notification);
            }
        }
    }
}
