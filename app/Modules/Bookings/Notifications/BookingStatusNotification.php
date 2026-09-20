<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells the other party that a booking moved along its lifecycle — the
 * customer when their provider accepts, declines, starts or completes a job,
 * and the provider when the customer calls one off.
 */
class BookingStatusNotification extends BaseNotification
{
    public function __construct(
        private readonly Booking $booking,
        private readonly string $status,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $reason = null,
    ) {}

    /** Muted by the "Booking updates" switch in the app's settings. */
    public function notificationCategory(): ?string
    {
        return 'booking';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_status',
            'action' => $this->status,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'booking_id' => $this->booking->id,
            'booking_number' => $this->booking->booking_number,
            'service_title' => $this->booking->service?->title,
            'status' => $this->status,
            'reason' => $this->reason,
        ];
    }
}
