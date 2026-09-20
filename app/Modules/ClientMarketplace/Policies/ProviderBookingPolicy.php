<?php

namespace App\Modules\ClientMarketplace\Policies;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

/**
 * A mobile provider may read and advance only the bookings placed against
 * their own provider profile, and only while the account is active.
 */
class ProviderBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveProvider($user);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->isActiveProvider($user)
            && (int) $booking->provider_id === (int) $user->providerProfile?->id;
    }

    public function transition(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    private function isActiveProvider(User $user): bool
    {
        return $user->isMobileProviderAccount() && $user->isActive();
    }
}
