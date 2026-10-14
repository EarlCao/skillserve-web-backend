<?php

namespace App\Modules\ClientCommunication\Policies;

use App\Models\User;
use App\Modules\ClientCommunication\Models\Inquiry;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * A conversation before a booking belongs to its customer and its provider,
 * the same two-party rule as a booking's chat ({@see BookingMessagePolicy}).
 */
class InquiryPolicy
{
    public function viewAny(User $user, Inquiry $inquiry): bool
    {
        // Either side must use a full client session token; narrower tokens
        // (e.g. the background notification token) never read a thread.
        if (! $user->isActive()
            || ! $user->currentAccessToken()?->can(config('client-auth.access_ability'))) {
            return false;
        }

        if ((int) $inquiry->customer_id === (int) $user->id) {
            return $user->isClientAccount() && $user->hasVerifiedEmail();
        }

        return $user->user_type === 'provider'
            && ! $user->roles()->exists()
            && ProviderProfile::query()
                ->whereKey($inquiry->provider_profile_id)
                ->where('user_id', $user->id)
                ->exists();
    }
}
