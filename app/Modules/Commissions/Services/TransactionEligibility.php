<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Shared\Exceptions\ApiException;

/**
 * Whether an account is currently allowed to take on new work.
 *
 * A provider who has been paid for a job holds SkillServe's share of it until
 * they remit it. While that debt stands they may not accept new bookings or
 * publish services — the platform would otherwise keep extending credit.
 *
 * Work the provider has already agreed to is never blocked. Starting,
 * completing, declining and cancelling all stay open, because a customer who
 * is already booked must not be stranded by a debt between the provider and
 * the platform — and a provider paid up front would otherwise be unable to
 * begin the very job that put them in debt.
 *
 * Customers are never blocked by this: they paid the advertised price in full,
 * and the unremitted share is not theirs to settle.
 *
 * This is the one place the rule lives, so the mobile app and the admin web
 * inherit identical behaviour from the API rather than each implementing it.
 */
class TransactionEligibility
{
    public function __construct(private readonly CommissionLedger $ledger) {}

    /**
     * The eligibility of [$user] as a provider, safe to expose to the app so
     * it can explain the block rather than guess at it.
     *
     * @return array{eligible: bool, reason: string|null, outstanding_total: string, outstanding_count: int}
     */
    public function forProvider(User $user): array
    {
        $profileId = $user->providerProfile?->id;

        if ($profileId === null) {
            return [
                'eligible' => false,
                'reason' => 'no_provider_profile',
                'outstanding_total' => '0.00',
                'outstanding_count' => 0,
            ];
        }

        $outstanding = $this->ledger->outstandingFor($profileId);
        $blocked = $outstanding['count'] > 0;

        return [
            'eligible' => ! $blocked,
            'reason' => $blocked ? 'outstanding_commission' : null,
            'outstanding_total' => number_format($outstanding['total'], 2, '.', ''),
            'outstanding_count' => $outstanding['count'],
        ];
    }

    /**
     * Refuse the action when [$user] owes SkillServe money.
     *
     * @throws ApiException
     */
    public function assertProviderCanTransact(User $user): void
    {
        $state = $this->forProvider($user);

        if ($state['eligible'] || $state['reason'] !== 'outstanding_commission') {
            return;
        }

        throw new ApiException(
            'Settle your outstanding commission before taking on new work.',
            403,
            errors: ['commission' => [sprintf(
                'You owe ₱%s across %d %s.',
                $state['outstanding_total'],
                $state['outstanding_count'],
                $state['outstanding_count'] === 1 ? 'booking' : 'bookings',
            )]],
        );
    }
}
