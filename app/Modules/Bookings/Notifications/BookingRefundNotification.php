<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\BookingRefund;
use Illuminate\Notifications\Notification;

/**
 * A refund owed on a cancelled booking moved. Never muted: it is money one
 * side owes the other, and missing it is how a refund goes unreturned.
 */
class BookingRefundNotification extends Notification
{
    public function __construct(
        private readonly BookingRefund $refund,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'booking_refund',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'booking_id' => $this->refund->booking_id,
            'booking_number' => $this->refund->booking?->booking_number,
            'amount' => $this->refund->amount,
        ];
    }
}
