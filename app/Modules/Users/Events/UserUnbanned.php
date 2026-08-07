<?php

namespace App\Modules\Users\Events;

use App\Models\User;

/**
 * Dispatched when a ban is lifted from a platform user account.
 */
class UserUnbanned
{
    /**
     * @param  User|null  $actor  null when the lift is system-driven
     *                            (expired temporary ban).
     */
    public function __construct(
        public readonly User $user,
        public readonly ?User $actor,
        public readonly ?string $reason,
    ) {}
}
