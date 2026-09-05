<?php

namespace App\Modules\ProviderRecognition\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

class ProviderRecognitionPolicy extends BasePolicy
{
    public function view(User $user): bool
    {
        return $user->hasPermissionTo('view provider recognition');
    }

    public function manageBadges(User $user): bool
    {
        return $user->hasPermissionTo('manage provider badges');
    }

    public function assignBadges(User $user): bool
    {
        return $user->hasPermissionTo('assign provider badges');
    }

    public function featured(User $user): bool
    {
        return $user->hasPermissionTo('manage featured providers');
    }

    public function topRated(User $user): bool
    {
        return $user->hasPermissionTo('view top rated providers');
    }
}
