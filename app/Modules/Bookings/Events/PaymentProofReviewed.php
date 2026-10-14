<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\BookingPaymentProof;

/** The provider confirmed or rejected a customer's payment proof. */
class PaymentProofReviewed
{
    public function __construct(
        public readonly BookingPaymentProof $proof,
        public readonly User $actor,
    ) {}
}
