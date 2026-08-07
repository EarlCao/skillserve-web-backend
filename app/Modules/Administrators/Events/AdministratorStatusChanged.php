<?php

namespace App\Modules\Administrators\Events;

use App\Models\User;

/**
 * Dispatched when an administrator account is activated or deactivated.
 */
class AdministratorStatusChanged
{
    public function __construct(
        public readonly User $administrator,
        public readonly User $actor,
        public readonly string $from,
        public readonly string $to,
    ) {}
}
