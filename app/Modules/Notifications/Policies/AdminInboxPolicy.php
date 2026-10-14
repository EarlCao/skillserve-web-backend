<?php

namespace App\Modules\Notifications\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * The admin Inbox belongs to every administrator: each sees only their own
 * alerts, which were sent according to their permissions. App accounts have
 * their own notification feed and never use it.
 */
class AdminInboxPolicy extends BasePolicy
{
    public function use(User $user): bool
    {
        return $user->isAdministrator();
    }
}
