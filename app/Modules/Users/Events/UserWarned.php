<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched when an administrator issues a formal warning to a user (a
 * moderation action on a report). The account stays active.
 */
class UserWarned
{
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
        public readonly string $reason,
    ) {}
}
