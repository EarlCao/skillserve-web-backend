<?php

namespace App\Modules\Commissions\Services;

use App\Models\User;
use App\Modules\Commissions\Events\CommissionRemittanceConfirmed;
use App\Modules\Commissions\Events\CommissionRemittanceSubmitted;
use App\Modules\Commissions\Models\CommissionRemittance;
use App\Modules\Payments\Exceptions\GatewayRequestFailed;
use App\Modules\Payments\Services\PayMongoClient;
use App\Modules\Providers\Models\ProviderProfile;
use App\Shared\Exceptions\ApiException;
use App\Shared\Services\BaseService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A provider pays SkillServe its commission by a PayMongo QR Ph code.
 *
 * The flow, as PayMongo documents it for dynamic QR Ph:
 *
 *   1. create a Payment Intent for the amount, allowing `qrph`
 *   2. create a Payment Method of type `qrph`
 *   3. attach it to the intent; the QR image is in
 *      `next_action.code.image_url` (a base-64 image)
 *   4. the provider scans it with GCash, Maya or a bank app
 *   5. PayMongo calls the webhook with `payment.paid` (or `payment.failed`);
 *      an unscanned code expires after 15 minutes (`qrph.expired`)
 *
 * The amount is always the provider's outstanding total, worked out here —
 * never taken from the app. Only the signed webhook confirms a payment, and
 * only when PayMongo's amount and currency match what was due.
 *
 * The money is SkillServe's own commission, so collecting it does not put
 * SkillServe in the customers' payment path (ADR-021).
 */
class CommissionQrPaymentService extends BaseService
{
    /**
     * How long a code stays payable. Sent to PayMongo as `expiry_seconds` on
     * the payment method (it accepts 60–9000; its own default is 30 minutes),
     * and PayMongo starts the clock at attach — when `expires_at` is set here.
     */
    public const EXPIRY_MINUTES = 15;

    public function __construct(
        private readonly PayMongoClient $client,
        private readonly CommissionRemittanceService $remittances,
    ) {}

    /** Whether QR payments can be offered: PayMongo keys usable here. */
    public function available(): bool
    {
        return $this->client->usable();
    }

    /**
     * A QR code for everything [$profile] owes now — or the one already open,
     * so tapping "Pay" twice never makes two codes for one debt.
     *
     * @return array{0: CommissionRemittance, 1: bool} the claim, and whether it is new
     */
    public function start(User $user, ProviderProfile $profile): array
    {
        if (! $this->available()) {
            throw new ApiException(
                'Paying by QR code is not available right now. Send it by GCash and submit the reference instead.',
                503,
            );
        }

        $this->remittances->expireStaleQr($profile->id);

        try {
            [$remittance, $created] = $this->transaction(function () use ($profile): array {
                // Serialises this provider's payment attempts.
                ProviderProfile::query()->whereKey($profile->id)->lockForUpdate()->first();

                if ($pending = $this->remittances->pendingFor($profile->id)) {
                    if ($pending->isPayableQr()) {
                        return [$pending, false];
                    }

                    throw new ApiException(
                        $pending->isQr()
                            ? 'Your QR code is still being prepared. Try again in a moment.'
                            : 'Your GCash payment is still waiting for SkillServe to confirm it.',
                        409,
                        errors: ['status' => ['Wait for the open payment to finish before starting another.']],
                    );
                }

                $bookings = $this->remittances->lockOutstanding($profile->id);
                $due = round((float) $bookings->sum('platform_fee'), 2);
                $this->assertAmountAcceptable($due);

                $remittance = CommissionRemittance::create([
                    'provider_profile_id' => $profile->id,
                    'method' => CommissionRemittance::METHOD_QRPH,
                    'amount_due' => $due,
                    'amount_sent' => $due,
                    // SkillServe's own reference for this payment, shown to the
                    // provider and the reviewer.
                    'reference' => 'QR-'.strtoupper(Str::random(10)),
                    'status' => CommissionRemittance::PENDING,
                ]);
                $this->remittances->attachCovered($remittance, $bookings);

                return [$remittance, true];
            });
        } catch (QueryException $exception) {
            if (str_contains($exception->getMessage(), 'commission_remittances_one_pending_per_provider')) {
                throw new ApiException('Your QR code is still being prepared. Try again in a moment.', 409);
            }

            throw $exception;
        }

        if (! $created) {
            return [$remittance->loadCount('bookings'), false];
        }

        // Outside the transaction: no lock is held across the calls to PayMongo.
        try {
            $this->createCode($remittance);
        } catch (GatewayRequestFailed $exception) {
            $remittance->update([
                'status' => CommissionRemittance::FAILED,
                'failure_reason' => $exception->getMessage(),
            ]);

            throw new ApiException(
                'We could not create a QR code right now. Please try again, or send it by GCash and submit the reference.',
                502,
            );
        }

        event(new CommissionRemittanceSubmitted(remittance: $remittance, actor: $user));

        return [$remittance->fresh()->loadCount('bookings'), true];
    }

    /**
     * Apply a verified PayMongo webhook to a commission QR payment. Returns
     * false when the event is not about one, so the caller can look elsewhere.
     *
     * @param  array<string, mixed>  $payment  the event's `data` object
     */
    public function applyWebhook(string $type, array $payment, string $eventId): bool
    {
        $intentId = $payment['attributes']['payment_intent_id'] ?? null;

        $remittance = $intentId === null
            ? null
            : CommissionRemittance::query()->where('external_id', $intentId)->first();

        if ($remittance === null) {
            return false;
        }

        match ($type) {
            'payment.paid' => $this->paid($remittance, $payment, $eventId),
            'payment.failed' => $this->closeUnpaid($remittance, CommissionRemittance::FAILED, $eventId,
                $payment['attributes']['last_payment_error']['message']
                    ?? (is_string($payment['attributes']['last_payment_error'] ?? null) ? $payment['attributes']['last_payment_error'] : 'The payment did not go through.')),
            'qrph.expired' => $this->closeUnpaid($remittance, CommissionRemittance::EXPIRED, $eventId, null),
            default => null,
        };

        return true;
    }

    /** @param  array<string, mixed>  $payment */
    private function paid(CommissionRemittance $remittance, array $payment, string $eventId): void
    {
        $result = $this->transaction(function () use ($remittance, $payment, $eventId): ?array {
            $remittance = CommissionRemittance::query()->lockForUpdate()->findOrFail($remittance->id);

            // PayMongo redelivers events; applying one twice must change nothing.
            if ($remittance->last_event_id === $eventId || $remittance->status === CommissionRemittance::CONFIRMED) {
                return null;
            }

            $paymentId = $payment['id'] ?? null;
            $amount = $payment['attributes']['amount'] ?? null;
            $currency = strtoupper((string) ($payment['attributes']['currency'] ?? ''));
            $expected = (int) round((float) $remittance->amount_due * 100);

            // A dynamic code fixes the amount, so a mismatch means something
            // is wrong: a person decides, nothing is settled automatically.
            if (! is_int($amount) || $amount !== $expected || $currency !== 'PHP') {
                Log::warning('A PayMongo payment did not match the commission payment it was for.', [
                    'commission_remittance_id' => $remittance->id,
                    'event_id' => $eventId,
                    'amount' => $amount,
                    'expected' => $expected,
                    'currency' => $currency,
                ]);

                $remittance->update([
                    'last_event_id' => $eventId,
                    'external_payment_id' => $paymentId,
                    'qr_image' => null,
                    'failure_reason' => sprintf(
                        'PayMongo reported %s %s, but ₱%s was due. Check the payment in the PayMongo dashboard, then confirm or reject it.',
                        is_int($amount) ? number_format($amount / 100, 2) : 'an unknown amount',
                        $currency !== '' ? $currency : '(no currency)',
                        number_format($expected / 100, 2),
                    ),
                ]);

                return null;
            }

            if ($remittance->status === CommissionRemittance::REJECTED) {
                $remittance->update([
                    'last_event_id' => $eventId,
                    'external_payment_id' => $paymentId,
                    'failure_reason' => 'PayMongo reported this payment as paid after it had been rejected. Check it in the PayMongo dashboard.',
                ]);

                return null;
            }

            // The payer records their own payment, as a gateway payment
            // involves no administrator.
            $payer = User::query()->find(
                ProviderProfile::query()->whereKey($remittance->provider_profile_id)->value('user_id'),
            );

            if ($payer === null) {
                return null;
            }

            $this->remittances->settleCovered($remittance, $payer, 'PayMongo payment '.($paymentId ?? $remittance->external_id));

            $remittance->update([
                'status' => CommissionRemittance::CONFIRMED,
                'reviewed_by' => null,
                'reviewed_at' => now(),
                'review_notes' => 'Paid by QR Ph; confirmed by PayMongo.',
                'external_payment_id' => $paymentId,
                'last_event_id' => $eventId,
                'qr_image' => null,
                'failure_reason' => null,
            ]);

            return [$remittance, $payer];
        });

        if ($result !== null) {
            [$confirmed, $payer] = $result;
            event(new CommissionRemittanceConfirmed(remittance: $confirmed, actor: $payer));
        }
    }

    /** The code was not paid: it failed, or nobody scanned it in time. */
    private function closeUnpaid(CommissionRemittance $remittance, string $status, string $eventId, ?string $reason): void
    {
        $this->transaction(function () use ($remittance, $status, $eventId, $reason): void {
            $remittance = CommissionRemittance::query()->lockForUpdate()->findOrFail($remittance->id);

            if ($remittance->last_event_id === $eventId || $remittance->status !== CommissionRemittance::PENDING) {
                return;
            }

            $remittance->update([
                'status' => $status,
                'last_event_id' => $eventId,
                'qr_image' => null,
                'failure_reason' => $reason,
            ]);
        });
    }

    private function createCode(CommissionRemittance $remittance): void
    {
        $key = 'commission-remittance-'.$remittance->id;

        $intent = $this->client->post('/payment_intents', [
            'amount' => (int) round((float) $remittance->amount_due * 100),
            'currency' => 'PHP',
            'payment_method_allowed' => ['qrph'],
            'description' => 'SkillServe commission '.$remittance->reference,
            'metadata' => [
                'commission_remittance_id' => (string) $remittance->id,
                'reference' => (string) $remittance->reference,
            ],
        ], $key);

        $intentId = $intent['id'] ?? null;
        if ($intentId === null) {
            throw new GatewayRequestFailed('PayMongo did not return a payment intent.');
        }
        $remittance->update(['external_id' => $intentId]);

        $method = $this->client->post('/payment_methods', [
            'type' => 'qrph',
            'expiry_seconds' => self::EXPIRY_MINUTES * 60,
        ], $key.':pm');

        $attached = $this->client->post("/payment_intents/{$intentId}/attach", [
            'payment_method' => $method['id'] ?? null,
            'client_key' => $intent['attributes']['client_key'] ?? null,
        ], $key.':attach');

        $image = $attached['attributes']['next_action']['code']['image_url'] ?? null;
        if (! is_string($image) || $image === '') {
            throw new GatewayRequestFailed('PayMongo did not return a QR code.');
        }

        $remittance->update([
            'qr_image' => $image,
            'expires_at' => now()->addMinutes(self::EXPIRY_MINUTES),
        ]);
    }

    private function assertAmountAcceptable(float $amount): void
    {
        $min = (float) config('payments.paymongo.min_amount', 1.0);
        $max = (float) config('payments.paymongo.max_amount', 100000.0);

        if ($amount < $min || $amount > $max) {
            throw new ApiException(
                sprintf('A QR payment must be between ₱%s and ₱%s. Send it by GCash and submit the reference instead.', number_format($min, 2), number_format($max, 2)),
                422,
                errors: ['amount' => ['This amount cannot be paid by QR code.']],
            );
        }
    }
}
