<?php

namespace App\Modules\Authentication\Events;

use App\Models\User;

/**
 * Dispatched after an administrator changes their password.
 */
class PasswordChanged
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {}
}
