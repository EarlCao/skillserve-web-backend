<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\BookingPaymentProof;
use App\Shared\Notifications\BaseNotification;

/**
 * A GCash payment proof moved: the provider hears that one was sent, the
 * customer that theirs was not accepted. (A confirmed proof is announced by
 * the payment it records — BookingStatusNotification.)
 */
class PaymentProofNotification extends BaseNotification
{
    public function __construct(
        private readonly BookingPaymentProof $proof,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
    ) {}

    /** Muted by the "Booking updates" switch in the app's settings. */
    public function notificationCategory(): ?string
    {
        return 'booking';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'payment_proof',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'booking_id' => $this->proof->booking_id,
            'booking_number' => $this->proof->booking?->booking_number,
            'reason' => $this->proof->review_note,
        ];
    }
}
