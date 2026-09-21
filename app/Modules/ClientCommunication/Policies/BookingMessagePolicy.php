<?php

namespace App\Modules\ClientCommunication\Policies;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Providers\Models\ProviderProfile;

class BookingMessagePolicy
{
    public function viewAny(User $user, Booking $booking): bool
    {
        return $this->participant($user, $booking);
    }

    public function create(User $user, Booking $booking): bool
    {
        return $this->participant($user, $booking);
    }

    private function participant(User $user, Booking $booking): bool
    {
        // Either side must use a full client session token; narrower tokens
        // (e.g. the background notification token) never read a thread.
        if (! $user->isActive()
            || ! $user->currentAccessToken()?->can(config('client-auth.access_ability'))) {
            return false;
        }

        if ($booking->client_id === $user->id) {
            return $user->isClientAccount() && $user->hasVerifiedEmail();
        }

        return $user->user_type === 'provider'
            && ! $user->roles()->exists()
            && ProviderProfile::query()
                ->whereKey($booking->provider_id)
                ->where('user_id', $user->id)
                ->exists();
    }
}
