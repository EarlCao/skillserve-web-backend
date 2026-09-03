<?php

namespace App\Modules\Reviews\Policies;

use App\Models\User;
use App\Modules\Reviews\Models\Review;
use App\Shared\Policies\BasePolicy;

class ReviewPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage reviews') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view reviews');
    }

    public function view(User $user, Review $review): bool
    {
        return $this->allows($user, 'view reviews');
    }

    public function hide(User $user, Review $review): bool
    {
        return $this->allows($user, 'edit reviews');
    }

    public function restore(User $user, Review $review): bool
    {
        return $this->allows($user, 'edit reviews');
    }

    public function delete(User $user, Review $review): bool
    {
        return $this->allows($user, 'delete reviews');
    }
}
