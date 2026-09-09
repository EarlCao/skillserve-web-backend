<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Events\BookingStatusChanged;

class LogBookingActivity
{
    public function handle(
        BookingStatusChanged|BookingCancelled|BookingDisputeManaged $event,
    ): void {
        match (true) {
            $event instanceof BookingStatusChanged => $this->log(
                'bookings', $event->actor, $event->booking,
                ['old_status' => $event->oldStatus, 'new_status' => $event->newStatus],
                'booking_status_changed',
            ),
            $event instanceof BookingCancelled => $this->log(
                'bookings', $event->actor, $event->booking,
                [
                    'reason' => $event->reason,
                    'payment_status' => $event->booking->payment_status,
                    'refund_processing' => 'not_processed',
                ],
                'booking_cancelled',
            ),
            $event instanceof BookingDisputeManaged => $this->log(
                'bookings', $event->actor, $event->booking,
                ['action' => $event->action, 'resolution' => $event->resolution],
                'booking_dispute_managed',
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function log(
        string $logName,
        User $actor,
        $subject,
        array $properties,
        string $description,
    ): void {
        activity($logName)
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
