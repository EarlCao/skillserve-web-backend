<?php

namespace App\Modules\Payments\Services;

use App\Modules\Bookings\Enums\PaymentMethod;
use App\Modules\Payments\Contracts\PaymentGateway;
use App\Modules\Payments\Gateways\ManualGateway;
use App\Modules\Payments\Gateways\PayMongoGateway;
use InvalidArgumentException;

/**
 * Resolves the gateway that handles a given payment method, from
 * `config/payments.php`.
 *
 * Keeping the mapping in configuration rather than in code is what lets GCash
 * move from manual settlement to PayMongo by changing one line, once the
 * integration exists.
 */
class PaymentGatewayManager
{
    /** @var array<string, class-string<PaymentGateway>> */
    private const GATEWAYS = [
        'manual' => ManualGateway::class,
        'paymongo' => PayMongoGateway::class,
    ];

    public function for(PaymentMethod $method): PaymentGateway
    {
        $name = config("payments.gateways.{$method->value}", 'manual');

        return $this->named($name);
    }

    public function named(string $name): PaymentGateway
    {
        $class = self::GATEWAYS[$name] ?? null;

        if ($class === null) {
            // A typo in configuration must not silently fall back to a gateway
            // that behaves differently about who holds the money.
            throw new InvalidArgumentException("Unknown payment gateway [{$name}].");
        }

        return app($class);
    }

    /**
     * Whether any configured gateway collects money itself. While this is
     * false, every booking settles by hand and every commission on a paid
     * booking becomes outstanding.
     */
    public function anyGatewayCollects(): bool
    {
        foreach (PaymentMethod::cases() as $method) {
            if ($this->for($method)->collectsPayment()) {
                return true;
            }
        }

        return false;
    }
}
