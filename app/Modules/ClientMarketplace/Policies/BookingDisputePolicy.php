<?php

namespace App\Modules\ClientMarketplace\Policies;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * Either party on a booking may raise a dispute about it. Both sides get the
 * same right: a provider can be let down by a customer just as easily.
 */
class BookingDisputePolicy
{
    public function raise(User $user, Booking $booking): bool
    {
        if (! $user->isMobileAccount() || ! $user->isActive()) {
            return false;
        }

        if ((int) $booking->client_id === (int) $user->id) {
            return true;
        }

        return ProviderProfile::query()
            ->whereKey($booking->provider_id)
            ->where('user_id', $user->id)
            ->exists();
    }
}
