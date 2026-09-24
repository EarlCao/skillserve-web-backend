<?php

namespace App\Modules\Bookings\Enums;

/**
 * The payment methods SkillServe supports. There are exactly two.
 *
 * `cash` is accepted on input as a deprecated alias for `on_hand`: the mobile
 * app in the field still sends it, and it is that build's *default*, so
 * rejecting it would fail every booking until the app is updated. It is
 * canonicalised to `on_hand` on the way in, so only the two values are ever
 * stored from now on.
 *
 * Bookings made before this change keep whatever they were stored with
 * (`credit_card`, `paypal`, …). Those values are display-only history and are
 * deliberately not rewritten.
 */
enum PaymentMethod: string
{
    /** Paid directly to the provider, in person. */
    case OnHand = 'on_hand';

    /** Paid through GCash. Recorded manually until PayMongo is integrated. */
    case GCash = 'gcash';

    /** Deprecated input aliases, mapped to a canonical method. */
    private const ALIASES = ['cash' => 'on_hand'];

    /** @return array<int, string> Values accepted from a client. */
    public static function accepted(): array
    {
        return [...array_column(self::cases(), 'value'), ...array_keys(self::ALIASES)];
    }

    /** @return array<int, string> Values ever written to the database. */
    public static function canonical(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Resolve client input to a canonical method, or null when absent. */
    public static function fromInput(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = self::ALIASES[$value] ?? $value;

        return self::tryFrom($value);
    }

    public function label(): string
    {
        return match ($this) {
            self::OnHand => 'On-hand payment',
            self::GCash => 'GCash',
        };
    }

    /**
     * Whether the money reaches the provider directly, which is what makes
     * them hold SkillServe's commission and owe it back.
     */
    public function isCollectedByProvider(): bool
    {
        // GCash is settled provider-to-customer by hand today. Once PayMongo
        // collects it, this becomes false for GCash and the commission is
        // never outstanding on those bookings.
        return true;
    }
}
