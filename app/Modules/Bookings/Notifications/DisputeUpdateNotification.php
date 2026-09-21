<?php

namespace App\Modules\Bookings\Notifications;

use App\Modules\Bookings\Models\Booking;
use App\Shared\Notifications\BaseNotification;

/** Tells both parties how an administrator is handling their dispute (M 11.5). */
class DisputeUpdateNotification extends BaseNotification
{
    public function __construct(
        private readonly Booking $booking,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $resolution = null,
    ) {}

    /** Muted by the "Booking updates" switch in the app's settings. */
    public function notificationCategory(): ?string
    {
        return 'booking';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'dispute_update',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'booking_id' => $this->booking->id,
            'booking_number' => $this->booking->booking_number,
            'reason' => $this->resolution,
        ];
    }
}
