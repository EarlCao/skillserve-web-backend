<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\PaymentProofReviewed;
use App\Modules\Bookings\Events\PaymentProofSubmitted;
use App\Modules\Bookings\Models\BookingPaymentProof;
use App\Modules\Bookings\Notifications\PaymentProofNotification;
use App\Modules\Providers\Models\ProviderProfile;

/**
 * The provider hears that a customer sent proof of payment; the customer
 * hears when theirs was rejected. A confirmed proof is announced by the
 * payment it records (NotifyPaymentParticipants).
 */
class NotifyPaymentProof
{
    public function handle(PaymentProofSubmitted|PaymentProofReviewed $event): void
    {
        $proof = $event->proof->loadMissing('booking.service:id,title');
        $booking = $proof->booking;
        $amount = $booking->currency.' '.number_format((float) $proof->amount, 2);
        $service = $booking->service?->title ? "\u{201C}{$booking->service->title}\u{201D}" : $booking->booking_number;

        if ($event instanceof PaymentProofSubmitted) {
            User::query()->find(ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'))?->notify(
                new PaymentProofNotification($proof, 'submitted', 'Payment proof to check',
                    "Your customer sent proof of a {$amount} GCash payment for {$service}. Check it in GCash, then confirm it."),
            );

            return;
        }

        if ($proof->status === BookingPaymentProof::REJECTED) {
            User::query()->find($booking->client_id)?->notify(
                new PaymentProofNotification($proof, 'rejected', 'Payment proof not accepted',
                    "The provider could not confirm your {$amount} payment for {$service}. Reason: {$proof->review_note}"),
            );
        }
    }
}
