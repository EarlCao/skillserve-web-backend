<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingCancelled;
use App\Modules\Bookings\Events\BookingDisputeManaged;
use App\Modules\Bookings\Events\BookingPaymentRecorded;
use App\Modules\Bookings\Events\BookingRescheduled;
use App\Modules\Bookings\Events\BookingStatusChanged;

class LogBookingActivity
{
    public function handle(
        BookingStatusChanged|BookingCancelled|BookingDisputeManaged|BookingRescheduled|BookingPaymentRecorded $event,
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
            $event instanceof BookingRescheduled => $this->log(
                'bookings', $event->actor, $event->booking,
                [
                    'previous_scheduled_date' => $event->previousStart?->toIso8601String(),
                    'previous_scheduled_end_date' => $event->previousEnd?->toIso8601String(),
                    'scheduled_date' => $event->booking->scheduled_date?->toIso8601String(),
                    'scheduled_end_date' => $event->booking->scheduled_end_date?->toIso8601String(),
                ],
                'booking_rescheduled',
            ),
            $event instanceof BookingPaymentRecorded => $this->log(
                'bookings', $event->actor, $event->booking,
                [
                    'action' => $event->action,
                    'old_payment_status' => $event->oldPaymentStatus,
                    'new_payment_status' => $event->booking->payment_status,
                    'amount' => $event->amount,
                    'payment_reference' => $event->booking->payment_reference,
                    'refund_reason' => $event->action === 'refunded' ? $event->booking->refund_reason : null,
                ],
                'booking_payment_recorded',
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
