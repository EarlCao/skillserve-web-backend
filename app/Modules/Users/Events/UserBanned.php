<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched when a platform user account is permanently banned.
 */
class UserBanned
{
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
