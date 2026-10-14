<?php

namespace App\Modules\Bookings\Listeners;

use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Services\BookingRefundService;

/**
 * A cancelled booking that had money paid on it — a downpayment or all of
 * it — opens a refund the provider must return, whoever cancelled.
 */
class OpenRefundOnCancellation
{
    public function __construct(private readonly BookingRefundService $refunds) {}

    public function handle(BookingCancelled $event): void
    {
        $this->refunds->openFor($event->booking->fresh(), $event->actor);
    }
}
