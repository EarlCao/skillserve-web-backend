<?php

namespace App\Modules\Payments\Gateways;

use App\Modules\Bookings\Models\Booking;
use App\Modules\Payments\Contracts\PaymentGateway;
use RuntimeException;

/**
 * How SkillServe works today: the customer pays the provider off-platform and
 * somebody records it afterwards (see ADR-007).
 *
 * This is not a placeholder — it is the real behaviour of both supported
 * methods right now, including GCash, which is settled between customer and
 * provider by hand until PayMongo is integrated.
 */
class ManualGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'manual';
    }

    /**
     * The money never passes through SkillServe, so the provider collects the
     * full advertised price and owes the commission back.
     */
    public function collectsPayment(): bool
    {
        return false;
    }

    public function collect(Booking $booking, string $idempotencyKey): array
    {
        // Calling this would mean some caller believes SkillServe can take
        // money. It cannot, and quietly returning success here is how a
        // booking ends up marked paid with nothing collected.
        throw new RuntimeException(
            'ManualGateway cannot collect payment; settlement is recorded by a person.',
        );
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        // No gateway, so no webhook can legitimately arrive.
        return false;
    }
}
