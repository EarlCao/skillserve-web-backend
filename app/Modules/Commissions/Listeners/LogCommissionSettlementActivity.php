<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Commissions\Events\CommissionSettled;
use App\Modules\Commissions\Events\CommissionWaived;

/**
 * Writes settlement decisions to the Spatie activity log. Money received and
 * money written off are both attributable to the administrator who recorded
 * them. Registered explicitly in AppServiceProvider.
 */
class LogCommissionSettlementActivity
{
    public function handle(CommissionSettled|CommissionWaived $event): void
    {
        [$properties, $description] = $event instanceof CommissionSettled
            ? [[
                'amount' => $event->settlement->amount,
                'method' => $event->settlement->method,
                'reference' => $event->settlement->reference,
            ], 'commission_settled']
            : [[
                'amount' => $event->booking->platform_fee,
                'reason' => $event->reason,
            ], 'commission_waived'];

        activity('commissions')
            ->causedBy($event->actor)
            ->performedOn($event->booking)
            ->withProperties($properties)
            ->log($description);
    }
}
