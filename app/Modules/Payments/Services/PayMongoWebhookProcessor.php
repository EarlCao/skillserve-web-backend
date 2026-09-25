<?php

namespace App\Modules\Payments\Services;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Bookings\Services\BookingPaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Shared\Services\BaseService;
use Illuminate\Support\Facades\Log;

/**
 * Applies a verified PayMongo webhook to a booking.
 *
 * This is the only place a GCash booking becomes paid. The customer's return
 * redirect is not trusted for that: it is a browser navigation anyone can
 * forge by visiting the URL.
 *
 * PayMongo redelivers events, so every event is deduplicated twice over — the
 * event id is recorded on the intent, and the underlying
 * {@see BookingPaymentService::markPaid()} refuses a booking that is already
 * paid. Double-crediting a booking is the failure this guards against.
 */
class PayMongoWebhookProcessor extends BaseService
{
    public function __construct(private readonly BookingPaymentService $payments) {}

    /**
     * @param  array<string, mixed>  $event  the decoded `data` object
     */
    public function handle(array $event): void
    {
        $eventId = $event['id'] ?? null;
        $type = $event['attributes']['type'] ?? null;

        if ($eventId === null || $type === null) {
            return;
        }

        // Only payment outcomes act on a booking. Anything else is
        // acknowledged so PayMongo stops retrying, and ignored.
        if (! in_array($type, ['payment.paid', 'payment.failed'], true)) {
            return;
        }

        $payment = $event['attributes']['data'] ?? [];
        $intentId = $payment['attributes']['payment_intent_id'] ?? null;

        $intent = $intentId === null
            ? null
            : PaymentIntent::query()->where('external_id', $intentId)->first();

        if ($intent === null) {
            // A payment SkillServe has no record of. Worth knowing about, but
            // not an error the caller can fix, so the webhook still succeeds.
            Log::warning('PayMongo webhook for an unknown payment intent.', [
                'event_id' => $eventId,
                'type' => $type,
            ]);

            return;
        }

        if ($intent->last_event_id === $eventId) {
            return;
        }

        $type === 'payment.paid'
            ? $this->applyPaid($intent, $payment, $eventId)
            : $this->applyFailed($intent, $payment, $eventId);
    }

    /** @param  array<string, mixed>  $payment */
    private function applyPaid(PaymentIntent $intent, array $payment, string $eventId): void
    {
        $this->transaction(function () use ($intent, $payment, $eventId): void {
            $intent = PaymentIntent::query()->lockForUpdate()->findOrFail($intent->id);

            if ($intent->last_event_id === $eventId || $intent->status === PaymentIntent::SUCCEEDED) {
                return;
            }

            $intent->update([
                'status' => PaymentIntent::SUCCEEDED,
                'paid_at' => now(),
                'last_event_id' => $eventId,
            ]);

            $booking = Booking::query()->find($intent->booking_id);

            if ($booking === null || $booking->payment_status !== 'unpaid') {
                return;
            }

            // Recorded against the account that owes the money — the customer
            // — because no administrator was involved in a gateway payment.
            $this->payments->markPaid(
                $booking,
                $booking->client,
                $payment['id'] ?? $intent->external_id,
                // A GCash payment can land before the job is done, so the
                // usual "payable" statuses are widened to include a booking
                // still awaiting the provider.
                array_unique([...BookingPaymentService::PAYABLE_STATUSES, 'pending']),
            );
        });
    }

    /** @param  array<string, mixed>  $payment */
    private function applyFailed(PaymentIntent $intent, array $payment, string $eventId): void
    {
        $intent->update([
            'status' => PaymentIntent::FAILED,
            'last_event_id' => $eventId,
            'failure_reason' => $payment['attributes']['last_payment_error'] ?? 'The GCash payment did not complete.',
        ]);
    }
}
