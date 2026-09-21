<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Notifications\BookingStatusNotification;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Helpers\BusinessTime;

/**
 * A customer moving a booking puts it back in the provider's requests, so
 * the provider is told the new time and asked to accept or decline it.
 */
class NotifyProviderOfReschedule
{
    public function handle(BookingRescheduled $event): void
    {
        $booking = $event->booking;
        $service = $booking->service?->title;
        $name = $service ? "\u{201C}{$service}\u{201D}" : 'a booking';
        $when = BusinessTime::format($booking->scheduled_date);

        // Resolved from the id for the same reason as NotifyBookingParticipants:
        // the loaded provider relation omits provider_profiles.user_id.
        $providerUser = User::query()->find(
            ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'),
        );

        if (! $providerUser || $providerUser->is($event->actor)) {
            return;
        }

        $providerUser->notify(new BookingStatusNotification(
            $booking, 'rescheduled', 'Booking rescheduled',
            "Your customer moved {$name} to {$when}. Accept or decline the new time.",
        ));
    }
}
