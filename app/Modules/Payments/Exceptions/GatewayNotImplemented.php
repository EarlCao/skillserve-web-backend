<?php

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\ApiException;

/**
 * Thrown when code reaches a gateway that exists only as scaffolding.
 *
 * This is deliberately loud. A payment gateway that silently pretended to
 * succeed would let a booking be marked paid when no money moved, which is the
 * single worst failure this subsystem can have.
 */
class GatewayNotImplemented extends ApiException
{
    public function __construct(string $gateway)
    {
        parent::__construct(
            'Online payment is not available yet.',
            503,
            errors: ['payment' => ["The {$gateway} integration is not implemented."]],
        );
    }
}
