<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\GatewayRequestFailed;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\PayMongoClient;

/**
 * GCash through PayMongo.
 *
 * The flow, as PayMongo documents it:
 *
 *   1. create a Payment Intent for the amount, allowing `gcash`
 *   2. create a Payment Method of type `gcash`
 *   3. attach the method to the intent, which returns
 *      `next_action.redirect.url`
 *   4. send the customer to that URL; they authorise in GCash
 *   5. PayMongo calls the webhook with `payment.paid` or `payment.failed`
 *
 * The **webhook** is what marks a booking paid — never the customer's return
 * to `return_url`, which is just a browser redirect an attacker can forge.
 *
 * Amounts are centavos. PayMongo refuses anything outside ₱1–₱100,000, so
 * that is checked here rather than surfacing a provider error code.
 *
 * > Money flow: PayMongo settles into **SkillServe's** account, not the
 * > provider's. SkillServe therefore keeps its commission automatically (the
 * > ledger settles it on payment) and owes the provider the net. Paying that
 * > out is a separate, currently manual, obligation — see the payouts note in
 * > the vault. Automatic splitting would need every provider onboarded as a
 * > PayMongo sub-account under PayMongo Platforms.
 */
class PayMongoGateway implements PaymentGateway
{
    public function __construct(private readonly PayMongoClient $client) {}

    public function name(): string
    {
        return 'paymongo';
    }

    /**
     * PayMongo takes the customer's money into SkillServe's account, so the
     * provider never holds the platform's share and the commission does not
     * become outstanding.
     */
    public function collectsPayment(): bool
    {
        return true;
    }

    /**
     * Start (or resume) collecting [$booking]'s total.
     *
     * Resuming matters: a customer who abandons the GCash screen and taps pay
     * again must be sent back to the same intent rather than charged twice.
     * The idempotency key is stored, so the open attempt is found first and
     * PayMongo's own 24-hour key replay is the second line of defence.
     *
     * @return array{intent_id: string, client_key: string|null, redirect_url: string, status: string}
     */
    public function collect(Booking $booking, string $idempotencyKey): array
    {
        $existing = PaymentIntent::query()
            ->where('booking_id', $booking->id)
            ->whereIn('status', PaymentIntent::OPEN)
            ->whereNotNull('redirect_url')
            ->latest('id')
            ->first();

        if ($existing !== null) {
            return [
                'intent_id' => (string) $existing->external_id,
                'client_key' => $existing->client_key,
                'redirect_url' => (string) $existing->redirect_url,
                'status' => $existing->status,
            ];
        }

        $amount = round((float) $booking->total_price, 2);
        $this->assertAmountAcceptable($amount);
        $minor = (int) round($amount * 100);

        $intent = $this->client->post('/payment_intents', [
            'amount' => $minor,
            'currency' => $booking->currency ?: 'PHP',
            'payment_method_allowed' => ['gcash'],
            'capture_type' => 'automatic',
            'description' => 'SkillServe booking '.$booking->booking_number,
            // Echoed back on the webhook, so the event can be tied to the
            // booking even if the local row were somehow missing.
            'metadata' => [
                'booking_id' => (string) $booking->id,
                'booking_number' => (string) $booking->booking_number,
            ],
        ], $idempotencyKey);

        $record = PaymentIntent::create([
            'booking_id' => $booking->id,
            'gateway' => $this->name(),
            'external_id' => $intent['id'] ?? null,
            'client_key' => $intent['attributes']['client_key'] ?? null,
            'status' => $intent['attributes']['status'] ?? 'awaiting_payment_method',
            'amount_minor' => $minor,
            'currency' => $booking->currency ?: 'PHP',
            'idempotency_key' => $idempotencyKey,
        ]);

        $method = $this->client->post('/payment_methods', [
            'type' => 'gcash',
            'billing' => array_filter([
                'name' => $booking->client?->name,
                'email' => $booking->client?->email,
                'phone' => $booking->contact_phone,
            ]),
        ], $idempotencyKey.':pm');

        $attached = $this->client->post("/payment_intents/{$record->external_id}/attach", [
            'payment_method' => $method['id'] ?? null,
            'client_key' => $record->client_key,
            'return_url' => $this->returnUrl($booking),
        ], $idempotencyKey.':attach');

        $status = $attached['attributes']['status'] ?? 'awaiting_next_action';
        $redirect = $attached['attributes']['next_action']['redirect']['url'] ?? null;

        if ($redirect === null) {
            $record->update(['status' => $status]);

            throw new GatewayRequestFailed('GCash did not return a payment page. Please try again.');
        }

        $record->update(['status' => $status, 'redirect_url' => $redirect]);

        return [
            'intent_id' => (string) $record->external_id,
            'client_key' => $record->client_key,
            'redirect_url' => $redirect,
            'status' => $status,
        ];
    }

    /**
     * Verify a webhook really came from PayMongo.
     *
     * The `Paymongo-Signature` header is `t=<unix>,te=<test>,li=<live>`. The
     * signed string is `<t>.<raw body>`, HMAC-SHA256 under the **webhook**
     * secret (not the API key), and the segment compared depends on whether
     * live or test credentials are in use.
     *
     * [$payload] must be the raw body: re-encoding JSON changes bytes and
     * breaks the signature on genuine requests.
     *
     * Returns false — never throws, and never assumes — when the secret is
     * missing, the header is malformed, the timestamp is outside tolerance, or
     * the digest does not match. The comparison is timing-safe.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        $secret = (string) config('payments.paymongo.webhook_secret');

        if ($secret === '' || $signature === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $signature) as $segment) {
            [$key, $value] = array_pad(explode('=', trim($segment), 2), 2, null);
            if ($key !== null && $value !== null) {
                $parts[$key] = $value;
            }
        }

        $timestamp = $parts['t'] ?? null;
        $provided = config('payments.paymongo.live') ? ($parts['li'] ?? null) : ($parts['te'] ?? null);

        if ($timestamp === null || $provided === null || ! ctype_digit($timestamp)) {
            return false;
        }

        // Replay protection: a captured request stays valid only briefly.
        $tolerance = (int) config('payments.paymongo.webhook_tolerance', 300);
        if ($tolerance > 0 && abs(time() - (int) $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        return hash_equals($expected, $provided);
    }

    /**
     * Where PayMongo sends the customer's browser afterwards. It only closes
     * the loop visually; the booking is marked paid by the webhook.
     */
    private function returnUrl(Booking $booking): string
    {
        return rtrim((string) config('app.url'), '/')."/payments/return/{$booking->booking_number}";
    }

    private function assertAmountAcceptable(float $amount): void
    {
        $min = (float) config('payments.paymongo.min_amount', 1.0);
        $max = (float) config('payments.paymongo.max_amount', 100000.0);

        if ($amount < $min || $amount > $max) {
            throw new GatewayRequestFailed(sprintf(
                'GCash payments must be between ₱%s and ₱%s.',
                number_format($min, 2),
                number_format($max, 2),
            ));
        }
    }
}
