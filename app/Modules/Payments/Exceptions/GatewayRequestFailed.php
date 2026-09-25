<?php

namespace App\Modules\Payments\Exceptions;

use App\Shared\Exceptions\ApiException;

/**
 * The payment provider refused a request, or could not be reached.
 *
 * 502 rather than 500: the failure is upstream, and the distinction matters
 * when reading logs after a payment incident. The provider's own message is
 * surfaced because it is customer-actionable ("amount below the minimum"),
 * but nothing else about the request is.
 */
class GatewayRequestFailed extends ApiException
{
    public function __construct(string $message)
    {
        parent::__construct($message, 502, errors: ['payment' => [$message]]);
    }
}
