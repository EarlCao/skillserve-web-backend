<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;

/**
 * Dispatched after an administrator's password is reset (by an administrator
 * with the manage administrators permission).
 */
class AdministratorPasswordChanged
{
    public function __construct(
        public readonly User $administrator,
        public readonly User $actor,
    ) {}
}
