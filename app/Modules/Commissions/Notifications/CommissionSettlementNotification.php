<?php

namespace App\Modules\Commissions\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells a provider that an administrator settled or waived the commission
 * they owed on a booking, and what, if anything, is still outstanding.
 *
 * The type contains "booking" and the payload carries `booking_id`, so the
 * app files it with booking updates and a tap opens that booking.
 */
class CommissionSettlementNotification extends BaseNotification
{
    public function __construct(
        private readonly Booking $booking,
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
            'type' => 'booking_commission',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'booking_id' => $this->booking->id,
            'booking_number' => $this->booking->booking_number,
            'commission_amount' => $this->booking->platform_fee,
        ];
    }
}
