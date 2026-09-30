<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\IdentityVerification\Services\IdentityGate;
use App\Modules\Settings\Services\SettingsService;
use App\Shared\Exceptions\ApiException;
use Illuminate\Support\Carbon;

/**
 * Whether an account is currently allowed to transact.
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
 * An administrator can soften the block in System Settings → Marketplace:
 * it applies once the unpaid total reaches a minimum amount, or once the
 * oldest unpaid commission is older than a number of days — whichever comes
 * first. The defaults (₱0, off) block on any debt at once.
 *
 * Customers are never blocked by this: they paid the advertised price in full,
 * and the unremitted share is not theirs to settle.
 *
 * This is the one place the rule lives, so the mobile app and the admin web
 * inherit identical behaviour from the API rather than each implementing it.
 */
class TransactionEligibility
{
    public function __construct(
        private readonly CommissionLedger $ledger,
        private readonly IdentityGate $identity,
        private readonly SettingsService $settings,
    ) {}

    /**
     * A customer's eligibility. Identity is the only thing that can stop them:
     * an unremitted commission is a debt between a provider and the platform,
     * never the customer's to settle.
     *
     * @return array{eligible: bool, reason: string|null, identity_status: string, identity_required: bool}
     */
    public function forCustomer(User $user): array
    {
        return $this->identityState($user);
    }

    /**
     * Refuse the action when [$user] has not verified an identity the platform
     * requires of them.
     *
     * @throws ApiException
     */
    public function assertIdentityVerified(User $user): void
    {
        if ($this->identity->allows($user)) {
            return;
        }

        throw new ApiException(
            'Verify your National ID before you can do this.',
            403,
            errors: ['identity' => [match ($this->identity->statusFor($user)) {
                'pending' => 'Your National ID is still being reviewed.',
                'rejected' => 'Your National ID was not accepted. Submit it again to continue.',
                default => 'Submit your Philippine National ID to start transacting.',
            }]],
        );
    }

    /** @return array{eligible: bool, reason: string|null, identity_status: string, identity_required: bool} */
    private function identityState(User $user): array
    {
        $required = $this->identity->appliesTo($user);
        $status = $this->identity->statusFor($user);
        $blocked = $required && $status !== 'verified';

        return [
            'eligible' => ! $blocked,
            'reason' => $blocked ? 'identity_'.$status : null,
            'identity_status' => $status,
            'identity_required' => $required,
        ];
    }

    /**
     * The eligibility of [$user] as a provider, safe to expose to the app so
     * it can explain the block rather than guess at it.
     *
     * `block_threshold` is the unpaid total at which the block starts, and
     * `block_deadline` the moment the oldest unpaid commission blocks by age
     * (null when that trigger is off or nothing is owed), so the app can warn
     * a provider before they are stopped.
     *
     * @return array{eligible: bool, reason: string|null, identity_status: string, identity_required: bool, outstanding_total: string, outstanding_count: int, block_threshold: string, block_deadline: string|null}
     */
    public function forProvider(User $user): array
    {
        $profileId = $user->providerProfile?->id;

        if ($profileId === null) {
            return [
                'eligible' => false,
                'reason' => 'no_provider_profile',
                'identity_status' => $this->identity->statusFor($user),
                'identity_required' => $this->identity->appliesTo($user),
                'outstanding_total' => '0.00',
                'outstanding_count' => 0,
                'block_threshold' => $this->money($this->blockThreshold()),
                'block_deadline' => null,
            ];
        }

        $outstanding = $this->ledger->outstandingFor($profileId);
        $threshold = $this->blockThreshold();
        $deadline = $this->blockDeadline($outstanding['oldest_paid_at']);
        $owes = $outstanding['count'] > 0 && (
            $outstanding['total'] >= $threshold
            || ($deadline !== null && ! now()->isBefore($deadline))
        );
        $identity = $this->identityState($user);

        // Identity is reported first: it is the more fundamental block, and
        // the app should send the provider to the ID screen rather than to a
        // settlement screen they cannot act on.
        $reason = match (true) {
            ! $identity['eligible'] => $identity['reason'],
            $owes => 'outstanding_commission',
            default => null,
        };

        return [
            'eligible' => $reason === null,
            'reason' => $reason,
            'identity_status' => $identity['identity_status'],
            'identity_required' => $identity['identity_required'],
            'outstanding_total' => $this->money($outstanding['total']),
            'outstanding_count' => $outstanding['count'],
            'block_threshold' => $this->money($threshold),
            'block_deadline' => $deadline?->toIso8601String(),
        ];
    }

    /** The unpaid total at which a provider is blocked; 0 means any debt. */
    private function blockThreshold(): float
    {
        return max(0.0, (float) $this->settings->value('marketplace', 'commission_block_min_amount'));
    }

    /**
     * When a debt that began at [$oldestPaidAt] starts blocking by age, or
     * null when the age trigger is off or nothing is owed.
     */
    private function blockDeadline(?Carbon $oldestPaidAt): ?Carbon
    {
        $days = (int) $this->settings->value('marketplace', 'commission_block_after_days');

        return $days > 0 && $oldestPaidAt !== null ? $oldestPaidAt->copy()->addDays($days) : null;
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Refuse the action when [$user] owes SkillServe money.
     *
     * @throws ApiException
     */
    public function assertProviderCanTransact(User $user): void
    {
        // Identity first, for the same reason forProvider() reports it first.
        $this->assertIdentityVerified($user);

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
