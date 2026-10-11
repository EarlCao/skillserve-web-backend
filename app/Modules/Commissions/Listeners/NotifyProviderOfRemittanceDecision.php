<?php

namespace App\Modules\Commissions\Listeners;

use App\Models\User;
use App\Modules\Commissions\Events\CommissionRemittanceConfirmed;
use App\Modules\Commissions\Events\CommissionRemittanceRejected;
use App\Modules\Commissions\Notifications\CommissionRemittanceNotification;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * A provider who submitted a GCash payment is waiting to take on work again,
 * so the decision reaches them at once — with what they still owe, if a
 * commission became outstanding while the payment waited.
 */
class NotifyProviderOfRemittanceDecision
{
    public function __construct(private readonly CommissionLedger $ledger) {}

    public function handle(CommissionRemittanceConfirmed|CommissionRemittanceRejected $event): void
    {
        $remittance = $event->remittance;
        // The full account: realtime delivery needs columns a narrow eager
        // load would leave out.
        $providerUser = User::query()->find(
            ProviderProfile::query()->whereKey($remittance->provider_profile_id)->value('user_id'),
        );

        if (! $providerUser) {
            return;
        }

        $amount = '₱'.number_format((float) $remittance->amount_sent, 2);
        $reference = $remittance->reference;

        $notification = $event instanceof CommissionRemittanceConfirmed
            ? new CommissionRemittanceNotification(
                $remittance, 'confirmed', 'Commission payment confirmed',
                "SkillServe received your {$amount} GCash payment (ref. {$reference}). {$this->remaining($remittance->provider_profile_id)}",
            )
            : new CommissionRemittanceNotification(
                $remittance, 'rejected', 'Commission payment not confirmed',
                "SkillServe could not confirm your {$amount} GCash payment (ref. {$reference}). Reason: {$remittance->review_notes} You can submit it again.",
            );

        $providerUser->notify($notification);
    }

    private function remaining(int $providerProfileId): string
    {
        $outstanding = $this->ledger->outstandingFor($providerProfileId);

        if ($outstanding['count'] === 0) {
            return 'You have no outstanding commission and can take on new work.';
        }

        return sprintf('You still owe ₱%s.', number_format($outstanding['total'], 2));
    }
}
