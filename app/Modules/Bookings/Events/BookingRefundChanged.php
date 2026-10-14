<?php

namespace App\Modules\Bookings\Events;

use App\Models\User;
use App\Modules\Bookings\Models\BookingRefund;

/**
 * A cancelled booking's refund moved: `opened`, `sent`, `confirmed`,
 * `not_received`, `overdue` or `resolved`. [actor] is null for the
 * scheduler's `overdue`.
 */
class BookingRefundChanged
{
    public function __construct(
        public readonly BookingRefund $refund,
        public readonly string $action,
        public readonly ?User $actor = null,
    ) {}
}
