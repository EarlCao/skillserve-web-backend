<?php

namespace App\Modules\ClientMarketplace\Listeners;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Notifications\ProviderStandingNotification;
use App\Modules\ClientMarketplace\Events\ClientBookingChanged;
use App\Modules\Providers\Models\ProviderProfile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * When an administrator suspends, bans or deletes a provider — or lifts a
 * suspension or ban — every customer with an open booking with them is told,
 * and their open booking screens reload to show it. Listens on the User model
 * so every path is covered: Provider Management, report moderation, deletion.
 */
class TellCustomersAboutProvider
{
    public function handle(User $user): void
    {
        $standing = $this->standing($user);

        if ($standing === null || $user->user_type !== 'provider') {
            return;
        }

        $profile = ProviderProfile::query()->where('user_id', $user->id)->first();

        // Back from an account suspension while the profile itself is still
        // suspended: still unavailable, so nothing changed for customers.
        if (! $profile || ($standing === 'active' && $profile->isSuspended())) {
            return;
        }

        $bookings = Booking::query()
            ->where('provider_id', $profile->id)
            ->whereIn('status', Booking::OPEN_STATUSES)
            ->with(['client', 'service:id,title'])
            ->get();

        foreach ($bookings as $booking) {
            $booking->client?->notify(new ProviderStandingNotification($booking, $profile->displayName(), $standing));
        }

        $changes = $bookings->map(fn (Booking $booking): array => [(int) $booking->client_id, (int) $booking->id])->all();

        DB::afterCommit(function () use ($changes): void {
            foreach ($changes as [$userId, $bookingId]) {
                try {
                    event(new ClientBookingChanged($userId, $bookingId));
                } catch (Throwable $exception) {
                    Log::warning('A provider standing change could not be pushed live.', ['booking_id' => $bookingId, 'error' => $exception->getMessage()]);
                }
            }
        });
    }

    /**
     * 'suspended', 'banned' or 'deleted' when the provider just became
     * unavailable, 'active' when they just became available again, else null.
     */
    private function standing(User $user): ?string
    {
        if (! $user->exists || $user->trashed()) {
            return 'deleted';
        }

        if (! $user->wasChanged('status')) {
            return null;
        }

        $before = $user->getPrevious()['status'] ?? null;

        return match (true) {
            $before === 'active' && ($user->isSuspended() || $user->isBanned()) => $user->status,
            in_array($before, ['suspended', 'banned'], true) && $user->isActive() => 'active',
            default => null,
        };
    }
}
