<?php

namespace App\Modules\Bookings\Services;

use App\Models\User;
use App\Modules\Bookings\Events\PaymentProofReviewed;
use App\Modules\Bookings\Events\PaymentProofSubmitted;
use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Models\BookingPaymentProof;
use App\Shared\Exceptions\ApiException;
use App\Shared\Helpers\PrivateImages;
use App\Shared\Services\BaseService;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Proof of GCash payments, sent by the customer and checked by the provider.
 *
 * SkillServe never holds the money (ADR-021): the customer pays the provider
 * in GCash — a downpayment or the whole amount, before or after the work —
 * and sends what they paid with a screenshot. The provider confirms it (the
 * amount is added to what has been paid, settling the booking once nothing
 * is left) or rejects it with a reason. One proof waits at a time.
 */
class BookingPaymentProofService extends BaseService
{
    public function __construct(private readonly BookingPaymentService $payments) {}

    /**
     * The customer's proof for [booking]. The screenshot is stored before the
     * transaction and removed again if the proof is refused.
     */
    public function submit(User $customer, Booking $booking, float $amount, ?string $reference, UploadedFile $image): BookingPaymentProof
    {
        $this->assertCanSubmit($customer, $booking, $amount);
        $stored = PrivateImages::store([$image], BookingPaymentProof::DISK, (string) $booking->id);

        try {
            return $this->transaction(function () use ($customer, $booking, $amount, $reference, $stored): BookingPaymentProof {
                // Re-checked under the lock, so two quick submits cannot both pass.
                $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
                $this->assertCanSubmit($customer, $booking, $amount);

                $proof = $booking->paymentProofs()->create([
                    'submitted_by' => $customer->id,
                    'amount' => round($amount, 2),
                    'reference' => $reference !== null && trim($reference) !== '' ? trim($reference) : null,
                    'image_path' => $stored[0]['path'],
                    'status' => BookingPaymentProof::PENDING,
                ]);

                event(new PaymentProofSubmitted($proof, $customer));

                return $proof;
            });
        } catch (\Throwable $exception) {
            PrivateImages::delete(BookingPaymentProof::DISK, $stored);

            throw $exception;
        }
    }

    /**
     * The provider accepts [proof]: its amount is recorded as received. A
     * proof for more than is still owed (the provider recorded cash in the
     * meantime) is refused — reject it instead.
     */
    public function confirm(User $provider, Booking $booking, BookingPaymentProof $proof): Booking
    {
        return $this->transaction(function () use ($provider, $booking, $proof): Booking {
            [$booking, $proof] = $this->lockPending($booking, $proof);

            if (! in_array($booking->payment_status, BookingPaymentService::OWING_STATUSES, true) || (float) $proof->amount > $booking->balanceDue()) {
                throw new ApiException(
                    'This booking no longer owes that much. Reject this proof instead.',
                    409,
                    errors: ['amount' => ['Still to pay: '.number_format($booking->balanceDue(), 2, '.', '').'.']],
                );
            }

            $proof->update([
                'status' => BookingPaymentProof::CONFIRMED,
                'reviewed_by' => $provider->id,
                'reviewed_at' => now(),
            ]);
            $booking = $this->payments->receive($booking, $provider, (float) $proof->amount, $proof->reference);

            event(new PaymentProofReviewed($proof, $provider));

            return $booking;
        });
    }

    /** The provider turns [proof] down, saying why; nothing is recorded as paid. */
    public function reject(User $provider, Booking $booking, BookingPaymentProof $proof, string $reason): Booking
    {
        return $this->transaction(function () use ($provider, $booking, $proof, $reason): Booking {
            [$booking, $proof] = $this->lockPending($booking, $proof);

            $proof->update([
                'status' => BookingPaymentProof::REJECTED,
                'reviewed_by' => $provider->id,
                'reviewed_at' => now(),
                'review_note' => trim($reason),
            ]);

            event(new PaymentProofReviewed($proof, $provider));

            return $booking;
        });
    }

    /** A proof's screenshot, when it belongs to [booking]. */
    public function image(Booking $booking, BookingPaymentProof $proof): StreamedResponse
    {
        abort_unless((int) $proof->booking_id === (int) $booking->id, 404);

        return PrivateImages::stream(BookingPaymentProof::DISK, $proof->image_path);
    }

    private function assertCanSubmit(User $customer, Booking $booking, float $amount): void
    {
        if ((int) $booking->client_id !== (int) $customer->id) {
            throw new ApiException('Booking not found.', 404);
        }

        if ($booking->payment_method !== 'gcash') {
            throw new ApiException(
                'Payment proof is for GCash payments. Pay this booking to the provider in person.',
                422,
                errors: ['payment_method' => ['This booking is paid on hand.']],
            );
        }

        if (! in_array($booking->status, BookingPaymentService::PAYABLE_STATUSES, true)) {
            throw new ApiException(
                'You can pay once the provider accepts this booking.',
                422,
                errors: ['status' => ['This booking is '.$booking->status.'.']],
            );
        }

        if (! in_array($booking->payment_status, BookingPaymentService::OWING_STATUSES, true)) {
            throw new ApiException('This booking is already paid.', 409, errors: ['payment_status' => ['The payment status is '.$booking->payment_status.'.']]);
        }

        if ($booking->paymentProofs()->where('status', BookingPaymentProof::PENDING)->exists()) {
            throw new ApiException(
                'Your last payment proof is still waiting for the provider.',
                409,
                errors: ['proof' => ['Wait for the provider to check it before sending another.']],
            );
        }

        $balance = $booking->balanceDue();
        if ($amount < 1 || round($amount, 2) > $balance) {
            throw new ApiException(
                'Enter an amount up to what is still to pay.',
                422,
                errors: ['amount' => ['Between 1.00 and '.number_format($balance, 2, '.', '').'.']],
            );
        }
    }

    /** @return array{0: Booking, 1: BookingPaymentProof} */
    private function lockPending(Booking $booking, BookingPaymentProof $proof): array
    {
        $booking = Booking::query()->lockForUpdate()->findOrFail($booking->id);
        $proof = BookingPaymentProof::query()->whereKey($proof->id)->where('booking_id', $booking->id)->lockForUpdate()->first()
            ?? throw new ApiException('Payment proof not found.', 404);

        if (! $proof->isPending()) {
            throw new ApiException('This payment proof was already '.$proof->status.'.', 409);
        }

        return [$booking, $proof];
    }
}
