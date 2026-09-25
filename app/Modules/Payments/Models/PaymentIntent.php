<?php

namespace App\Modules\Payments\Models;

use App\Modules\Bookings\Models\Booking;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt to collect a booking's total through a gateway.
 *
 * `client_key` is hidden from serialisation: it is the value that lets a
 * client talk to the gateway about this intent, and it has no business
 * appearing in a log or a list response. The pay endpoint returns it
 * explicitly, once, to the customer who owns the booking.
 */
#[Fillable([
    'booking_id', 'gateway', 'external_id', 'client_key', 'status',
    'amount_minor', 'currency', 'idempotency_key', 'redirect_url',
    'last_event_id', 'paid_at', 'failure_reason',
])]
#[Hidden(['client_key'])]
class PaymentIntent extends Model
{
    public const SUCCEEDED = 'succeeded';

    public const FAILED = 'failed';

    /** Attempts that can still turn into a payment. */
    public const OPEN = ['awaiting_payment_method', 'awaiting_next_action', 'processing'];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN, true);
    }

    /** Pesos, from the centavos the gateway counts in. */
    public function amount(): float
    {
        return round($this->amount_minor / 100, 2);
    }

    protected function casts(): array
    {
        return ['paid_at' => 'datetime'];
    }
}
