<?php

namespace App\Modules\Commissions\Listeners;

use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Commissions\Services\CommissionLedger;

/**
 * A cancelled job earns SkillServe nothing, so any commission still owed on
 * it is dropped. Registered explicitly in AppServiceProvider.
 *
 * A commission already settled is untouched — the ledger refuses to reopen it.
 */
class VoidCommissionOnCancellation
{
    public function __construct(private readonly CommissionLedger $ledger) {}

    public function handle(BookingCancelled $event): void
    {
        $this->ledger->void($event->booking);
    }
}
