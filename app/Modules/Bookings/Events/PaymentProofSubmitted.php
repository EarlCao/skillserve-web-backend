<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\BookingPaymentProof;

/** A customer sent proof of a GCash payment for the provider to check. */
class PaymentProofSubmitted
{
    public function __construct(
        public readonly BookingPaymentProof $proof,
        public readonly User $actor,
    ) {}
}
