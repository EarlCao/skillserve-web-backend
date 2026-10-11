<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Commissions\Events\CommissionRemittanceConfirmed;
use App\Modules\Commissions\Events\CommissionRemittanceRejected;
use App\Modules\Commissions\Events\CommissionRemittanceSubmitted;

/**
 * Writes each step of a GCash commission payment to the Spatie activity log:
 * who claimed to have paid, and which administrator accepted or refused it.
 * Registered explicitly in AppServiceProvider.
 */
class LogCommissionRemittanceActivity
{
    public function handle(CommissionRemittanceSubmitted|CommissionRemittanceConfirmed|CommissionRemittanceRejected $event): void
    {
        $remittance = $event->remittance;

        $description = match (true) {
            $event instanceof CommissionRemittanceSubmitted => 'commission_remittance_submitted',
            $event instanceof CommissionRemittanceConfirmed => 'commission_remittance_confirmed',
            default => 'commission_remittance_rejected',
        };

        activity('commissions')
            ->causedBy($event->actor)
            ->performedOn($remittance)
            ->withProperties([
                'reference' => $remittance->reference,
                'amount_due' => $remittance->amount_due,
                'amount_sent' => $remittance->amount_sent,
                'review_notes' => $remittance->review_notes,
            ])
            ->log($description);
    }
}
