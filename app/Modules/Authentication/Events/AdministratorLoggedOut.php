<?php

namespace App\Modules\Authentication\Events;

use App\Models\User;

/**
 * Dispatched after an administrator's session is terminated.
 */
class AdministratorLoggedOut
{
    public function __construct(
        public readonly User $user,
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
    ) {
    }
}
