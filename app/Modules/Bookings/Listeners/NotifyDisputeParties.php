<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Notifications\DisputeUpdateNotification;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * Both parties hear each administrator decision on their dispute. Internal
 * notes stay internal.
 */
class NotifyDisputeParties
{
    public function handle(BookingDisputeManaged $event): void
    {
        $booking = $event->booking;
        $number = $booking->booking_number;

        $notification = match ($event->action) {
            'investigate' => new DisputeUpdateNotification(
                $booking, 'investigating', 'Dispute under review',
                "Our team is now investigating the dispute on booking {$number}.",
            ),
            'resolve' => new DisputeUpdateNotification(
                $booking, 'resolved', 'Dispute resolved',
                "The dispute on booking {$number} was resolved. Decision: {$event->resolution}",
                $event->resolution,
            ),
            'reject' => new DisputeUpdateNotification(
                $booking, 'rejected', 'Dispute closed without action',
                "The dispute on booking {$number} was reviewed and not upheld."
                    .($event->resolution ? " Reason: {$event->resolution}" : ''),
                $event->resolution,
            ),
            'close' => new DisputeUpdateNotification(
                $booking, 'closed', 'Dispute closed',
                "The dispute on booking {$number} is now closed.",
            ),
            default => null,
        };

        if (! $notification) {
            return;
        }

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
