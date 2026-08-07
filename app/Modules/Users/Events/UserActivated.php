<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched when a suspended platform user account is restored.
 */
class UserActivated
{
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
    ) {}
}
