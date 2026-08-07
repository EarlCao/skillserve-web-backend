<?php

namespace App\Modules\Authentication\Events;

use App\Models\User;

/**
 * Dispatched after an administrator successfully authenticates.
 */
class AdministratorLoggedIn
{
    public function __construct(
        public readonly User $user,
        public readonly string $token,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
