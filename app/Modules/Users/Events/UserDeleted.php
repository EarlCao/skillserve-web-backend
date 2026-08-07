<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched after a platform user account is soft-deleted.
 */
class UserDeleted
{
    public function __construct(
        public readonly User $user,
        public readonly User $actor,
    ) {}
}
