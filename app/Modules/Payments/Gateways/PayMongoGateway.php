<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Exceptions\GatewayNotImplemented;

/**
 * PayMongo — SCAFFOLDING ONLY. Nothing here talks to PayMongo.
 *
 * This class exists so the shape of the integration is settled and the rest of
 * the platform can be written against {@see PaymentGateway} instead of against
 * a specific provider. Every method that would move money throws.
 *
 * Before implementing it, the current PayMongo API and its supported
 * Philippine flows must be checked against what SkillServe actually needs —
 * in particular whether the commission can be split off automatically or has
 * to be reconciled afterwards. That question is open, and nothing in this
 * codebase should be read as assuming an answer.
 *
 * What implementing it will involve:
 * - credentials from configuration, never committed;
 * - a payment intent per booking, keyed by an idempotency key so a retried
 *   request cannot charge twice;
 * - a webhook endpoint that verifies PayMongo's signature before trusting the
 *   payload, and that is itself idempotent because gateways redeliver;
 * - marking the booking paid from the *webhook*, not from the client's return
 *   redirect, which is attacker-controlled;
 * - deciding how refunds and the commission interact.
 */
class PayMongoGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'paymongo';
    }

    /**
     * True once implemented: PayMongo would hold the money, so the provider
     * would never hold SkillServe's share and the commission would not become
     * outstanding on these bookings.
     */
    public function collectsPayment(): bool
    {
        return true;
    }

    public function collect(Booking $booking, string $idempotencyKey): array
    {
        throw new GatewayNotImplemented('PayMongo');
    }

    /**
     * Returns false rather than throwing: an unverifiable webhook must be
     * refused, not turned into a server error that a caller might retry.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        return false;
    }
}
