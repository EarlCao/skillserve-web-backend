<?php

namespace App\Modules\Notifications\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

class AnnouncementPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view notifications');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('send announcements');
    }
}
