<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched when a platform user account is temporarily suspended.
 */
class UserSuspended
{
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
