<?php

namespace App\Modules\Bookings\Listeners;

use App\Models\User;
use App\Modules\Bookings\Events\BookingRefundChanged;
use App\Modules\Bookings\Notifications\BookingRefundNotification;
use App\Modules\Providers\Models\ProviderProfile;

/** Tells the customer and the provider where a refund owed between them stands. */
class NotifyRefundParties
{
    public function handle(BookingRefundChanged $event): void
    {
        $refund = $event->refund->loadMissing('booking');
        $booking = $refund->booking;
        $amount = $booking->currency.' '.number_format((float) $refund->amount, 2);
        $by = $refund->due_by->timezone(config('app.business_timezone', 'Asia/Manila'))->format('M j, g:i A');
        $customer = User::query()->find($booking->client_id);
        $provider = User::query()->find(ProviderProfile::query()->whereKey($booking->provider_id)->value('user_id'));
        $notice = fn (string $title, string $message) => new BookingRefundNotification($refund, $event->action, $title, $message);

        match ($event->action) {
            'opened' => [
                $customer?->notify($notice('Your payment will be returned',
                    "Booking {$booking->booking_number} was cancelled. The provider must return your {$amount} by {$by}. You will confirm it once it reaches you.")),
                $provider?->notify($notice('Return the customer\'s payment',
                    "Booking {$booking->booking_number} was cancelled. Return {$amount} to the customer in GCash by {$by} and upload the proof, or you will not be able to take new bookings.")),
            ],
            'sent' => $customer?->notify($notice('Check your GCash',
                "The provider says they returned {$amount} for {$booking->booking_number}. Confirm it once it shows in your GCash, or tell us it did not arrive.")),
            'confirmed' => $provider?->notify($notice('Refund confirmed',
                "The customer confirmed receiving {$amount} for {$booking->booking_number}. Thank you.")),
            'not_received' => $provider?->notify($notice('Customer did not receive the refund',
                "The customer says the {$amount} for {$booking->booking_number} did not arrive. Our team is reviewing it; you cannot take new bookings until it is settled.")),
            'overdue' => $provider?->notify($notice('Refund overdue',
                "You have not returned {$amount} for {$booking->booking_number}. You cannot take new bookings until you do, and our team has been told.")),
            'resolved' => [
                $customer?->notify($notice('Refund case closed',
                    $refund->resolution === 'returned'
                        ? "SkillServe confirmed the {$amount} for {$booking->booking_number} was returned to you."
                        : "SkillServe found the {$amount} for {$booking->booking_number} was not returned and is acting on the provider's account. {$refund->resolution_note}")),
                $provider?->notify($notice('Refund case closed',
                    $refund->resolution === 'returned'
                        ? "SkillServe confirmed you returned {$amount} for {$booking->booking_number}."
                        : "SkillServe found you did not return {$amount} for {$booking->booking_number}. {$refund->resolution_note}")),
            ],
            default => null,
        };
    }
}
