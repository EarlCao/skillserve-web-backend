<?php

namespace App\Modules\Payments\Contracts;

use App\Modules\Bookings\Models\Booking;

/**
 * How a payment method actually moves money.
 *
 * SkillServe has always *recorded* payments rather than processing them
 * (see ADR-007). This contract exists so that a real gateway can be added
 * without the rest of the platform learning about it: booking, commission and
 * settlement code asks the gateway whether it collects money, and behaves
 * accordingly.
 *
 * Implementations must be safe to call more than once for the same booking:
 * a retried request, a duplicated webhook or a double tap must not charge
 * twice. That is why {@see self::collect()} takes an idempotency key rather
 * than generating one.
 */
interface PaymentGateway
{
    /** Stable identifier, e.g. "manual" or "paymongo". */
    public function name(): string;

    /**
     * Whether this gateway takes the customer's money itself.
     *
     * False means the money passes directly between customer and provider, so
     * the provider ends up holding SkillServe's commission and owes it back.
     * True means the gateway collects, and the commission never becomes
     * outstanding. The commission ledger keys off this rather than off the
     * payment method, so adding a gateway does not mean editing the ledger.
     */
    public function collectsPayment(): bool;

    /**
     * Begin collecting [$booking]'s total.
     *
     * @param  string  $idempotencyKey  Replaying the same key must return the
     *                                  original result rather than charge again.
     * @return array<string, mixed> Gateway reference and whatever the client
     *                              needs to continue (e.g. a redirect URL).
     */
    public function collect(Booking $booking, string $idempotencyKey): array;

    /**
     * Verify that a webhook really came from the gateway.
     *
     * Payment webhooks are unauthenticated public endpoints, so a signature
     * check is the only thing standing between the platform and an attacker
     * marking arbitrary bookings paid. An implementation that cannot verify
     * must return false rather than assume.
     */
    public function verifyWebhook(string $payload, string $signature): bool;
}
