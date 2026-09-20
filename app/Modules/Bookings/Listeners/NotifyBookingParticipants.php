<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingStatusChanged;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * Every booking status change tells the people it affects — but never the
 * person who made it. A provider accepting a job notifies the customer; a
 * customer calling one off notifies the provider; an administrator cancelling
 * notifies both.
 */
class NotifyBookingParticipants
{
    public function handle(BookingStatusChanged $event): void
    {
        $booking = $event->booking;
        $service = $booking->service?->title;
        $name = $service ? "\u{201C}{$service}\u{201D}" : 'your booking';
        $reason = $booking->status === 'cancelled' ? $booking->cancellation_reason : null;

        // Resolve both accounts from their ids rather than the loaded
        // relations: callers eager-load the parties with narrow column lists
        // that omit provider_profiles.user_id (which would silently skip the
        // provider) and users.role_id (needed to push the notification to the
        // mobile app in realtime).
        $client = User::query()->find($booking->client_id);
        $providerUser = User::query()->find(
            ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'),
        );

        $notification = match ($event->newStatus) {
            'confirmed' => new BookingStatusNotification(
                $booking, 'confirmed', 'Booking accepted',
                "Your provider accepted your booking for {$name}.",
            ),
            'active' => new BookingStatusNotification(
                $booking, 'active', 'Job started',
                "Work has started on your booking for {$name}.",
            ),
            'completed' => new BookingStatusNotification(
                $booking, 'completed', 'Job completed',
                "Your booking for {$name} is marked as completed. You can now leave a review.",
            ),
            'cancelled' => new BookingStatusNotification(
                $booking, 'cancelled', 'Booking cancelled',
                "The booking for {$name} was cancelled.".($reason ? " Reason: {$reason}" : ''),
                $reason,
            ),
            default => null,
        };

        if (! $notification) {
            return;
        }

        // A cancellation concerns both sides; the rest are the customer's news.
        $recipients = $event->newStatus === 'cancelled'
            ? [$client, $providerUser]
            : [$client];

        foreach ($recipients as $recipient) {
            if ($recipient && ! $recipient->is($event->actor)) {
                $recipient->notify($notification);
            }
        }
    }
}
