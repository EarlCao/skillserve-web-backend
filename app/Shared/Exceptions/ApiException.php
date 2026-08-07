<?php

namespace App\Shared\Exceptions;

use Exception;

/**
 * Application-level exception that maps directly to the standard API error
 * envelope. Throw from Services/Actions when a known, reportable error occurs.
 */
class ApiException extends Exception
{
    public function __construct(
        string $message = 'Something went wrong.',
        public readonly int $status = 500,
        public readonly array $errors = [],
        public readonly array $meta = [],
    ) {
        parent::__construct($message, $status);
    }
}
