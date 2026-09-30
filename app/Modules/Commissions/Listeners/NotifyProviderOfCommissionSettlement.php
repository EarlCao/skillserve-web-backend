<?php

namespace App\Modules\Commissions\Listeners;

use App\Models\User;
use App\Modules\Commissions\Events\CommissionSettled;
use App\Modules\Commissions\Events\CommissionWaived;
use App\Modules\Commissions\Notifications\CommissionSettlementNotification;
use App\Modules\Commissions\Services\CommissionLedger;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * An outstanding commission can stop a provider taking new work, so when an
 * administrator settles or waives one the provider is told at once, along
 * with whatever they still owe, instead of finding out by refreshing.
 */
class NotifyProviderOfCommissionSettlement
{
    public function __construct(private readonly CommissionLedger $ledger) {}

    public function handle(CommissionSettled|CommissionWaived $event): void
    {
        $booking = $event->booking;
        // The full account: realtime delivery needs columns a narrow eager
        // load would leave out.
        $providerUser = User::query()->find(
            ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'),
        );

        if (! $providerUser || $providerUser->is($event->actor)) {
            return;
        }

        $amount = '₱'.number_format((float) $booking->platform_fee, 2);
        $number = $booking->booking_number;
        $remaining = $this->remaining($booking->provider_id);

        $notification = $event instanceof CommissionSettled
            ? new CommissionSettlementNotification(
                $booking, 'settled', 'Commission payment recorded',
                "SkillServe recorded your {$amount} commission for booking {$number}. {$remaining}",
            )
            : new CommissionSettlementNotification(
                $booking, 'waived', 'Commission waived',
                "An administrator waived your {$amount} commission for booking {$number}. Reason: {$event->reason} {$remaining}",
            );

        $providerUser->notify($notification);
    }

    private function remaining(int $providerProfileId): string
    {
        $outstanding = $this->ledger->outstandingFor($providerProfileId);

        if ($outstanding['count'] === 0) {
            return 'You have no outstanding commission.';
        }

        return sprintf(
            'You still owe ₱%s across %d %s.',
            number_format($outstanding['total'], 2),
            $outstanding['count'],
            $outstanding['count'] === 1 ? 'booking' : 'bookings',
        );
    }
}
