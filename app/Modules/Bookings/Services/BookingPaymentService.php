<?php

namespace App\Modules\Bookings\Services;

use App\Models\User;
use App\Modules\Bookings\Events\BookingPaymentRecorded;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;

/**
 * The one place settlement rules live. No payment provider is called:
 * payment happens off-platform (cash, GCash, …) and is recorded here, by
 * the provider who received it or by an administrator.
 *
 * unpaid ──markPaid──▶ paid ──refund (part)──▶ partially_refunded ──refund (rest)──▶ refunded
 *                        └────────refund (all)──────────────────────────────────────▶ refunded
 *
 * Every write re-reads the booking under a row lock, so two people recording
 * the same payment cannot both succeed.
 */
class BookingPaymentService extends BaseService
{
    /** Work was agreed or done, so payment can be owed. */
    public const PAYABLE_STATUSES = ['confirmed', 'active', 'completed', 'disputed'];

    /**
     * Mark an unpaid booking as paid. [$allowedStatuses] narrows who may do
     * it when: a provider only after completing the job.
     *
     * @param  array<int, string>  $allowedStatuses
     */
    public function markPaid(
        Booking $booking,
        User $actor,
        ?string $reference = null,
        array $allowedStatuses = self::PAYABLE_STATUSES,
    ): Booking {
        return $this->transaction(function () use ($booking, $actor, $reference, $allowedStatuses): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if ($booking->payment_status !== 'unpaid') {
                throw new ApiException(
                    'This booking is already marked as paid.',
                    409,
                    errors: ['payment_status' => ['The payment status is '.$booking->payment_status.'.']],
                );
            }

            if (! in_array($booking->status, $allowedStatuses, true)) {
                throw new ApiException(
                    'This booking cannot be marked as paid in its current status.',
                    422,
                    errors: ['status' => ['This booking is '.$booking->status.'.']],
                );
            }

            $booking->update([
                'payment_status' => 'paid',
                'paid_at' => now(),
                'payment_recorded_by' => $actor->id,
                'payment_reference' => $reference ?? $booking->payment_reference,
            ]);

            event(new BookingPaymentRecorded($booking, $actor, 'paid', 'unpaid', (float) $booking->total_price));

            return $booking;
        });
    }

    /**
     * Record a refund of [$amount] against a paid booking. Refunding the
     * whole remaining amount makes it `refunded`; less, `partially_refunded`.
     * The money itself moves off-platform.
     */
    public function refund(Booking $booking, User $actor, float $amount, string $reason): Booking
    {
        return $this->transaction(function () use ($booking, $actor, $amount, $reason): Booking {
            $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);

            if (! in_array($booking->payment_status, ['paid', 'partially_refunded'], true)) {
                throw new ApiException(
                    'Only a paid booking can be refunded.',
                    422,
                    errors: ['payment_status' => ['The payment status is '.$booking->payment_status.'.']],
                );
            }

            $refunded = round((float) $booking->refunded_amount, 2);
            $remaining = round((float) $booking->total_price - $refunded, 2);
            $amount = round($amount, 2);

            if ($amount > $remaining) {
                throw new ApiException(
                    'The refund is more than what is left to refund.',
                    422,
                    errors: ['amount' => ['At most '.number_format($remaining, 2, '.', '').' can still be refunded.']],
                );
            }

            $oldPaymentStatus = $booking->payment_status;
            $total = round($refunded + $amount, 2);

            $booking->update([
                'payment_status' => $total >= round((float) $booking->total_price, 2) ? 'refunded' : 'partially_refunded',
                'refunded_amount' => $total,
                'refunded_at' => now(),
                'refund_reason' => $reason,
            ]);

            event(new BookingPaymentRecorded($booking, $actor, 'refunded', $oldPaymentStatus, $amount));

            return $booking;
        });
    }
}
