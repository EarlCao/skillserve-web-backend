<?php

namespace App\Modules\Providers\Actions;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingRefund;
use App\Modules\ClientAuthentication\Services\ClientSessionService;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;
use App\Modules\Users\Actions\DeleteUserAction;
use App\Shared\Exceptions\ApiException;

/**
 * Soft-deletes a provider's account: they leave the marketplace and are
 * signed out everywhere. The profile, services and booking history stay for
 * the records, and Data Management can restore the account.
 *
 * A provider who still owes something cannot be deleted, so nobody vanishes
 * with a customer's money or the company's commission: open bookings, an
 * unreturned downpayment, or unpaid commission have to be settled first.
 */
class DeleteProviderAction
{
    public function __construct(
        private readonly DeleteUserAction $deleteUserAction,
        private readonly ClientSessionService $clientSessionService,
        private readonly CommissionLedger $commissionLedger,
    ) {}

    /**
     * @throws ApiException 409 while the provider has unsettled obligations.
     */
    public function handle(ProviderProfile $profile, User $actor): ProviderProfile
    {
        // Taken before the checks so a booking cannot slip in meanwhile:
        // creating one holds a shared lock on the same row.
        ProviderProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

        $user = $profile->user;

        if (! $user) {
            throw new ApiException(
                'This provider account is already deleted.',
                422,
                errors: ['id' => ['The provider account is already deleted.']],
            );
        }

        if ($blocker = $this->blocker($profile)) {
            throw new ApiException(
                "This provider cannot be deleted while they have {$blocker}.",
                409,
                errors: ['provider' => ["Settle the provider's {$blocker} first."]],
            );
        }

        $this->deleteUserAction->handle($user, $actor);
        $this->clientSessionService->revokeRefreshTokens($user);

        return $profile;
    }

    private function blocker(ProviderProfile $profile): ?string
    {
        $bookings = Booking::query()->where('provider_id', $profile->id);

        return match (true) {
            (clone $bookings)->whereIn('status', Booking::OPEN_STATUSES)->exists() => 'open bookings',
            BookingRefund::query()
                ->whereIn('status', BookingRefund::OPEN)
                ->whereHas('booking', fn ($booking) => $booking->where('provider_id', $profile->id))
                ->exists() => 'a downpayment not yet returned to a customer',
            $this->commissionLedger->outstandingQuery($profile->id)->exists() => 'unpaid commission',
            default => null,
        };
    }
}
