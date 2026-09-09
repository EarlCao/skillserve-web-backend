<?php

namespace App\Modules\ClientMarketplace\Policies;

use App\Models\User;
use App\Modules\Reviews\Models\Review;

class ClientReviewPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveClient($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveClient($user);
    }

    public function view(User $user, Review $review): bool
    {
        return $this->isActiveClient($user) && $review->reviewer_id === $user->id;
    }

    public function update(User $user, Review $review): bool
    {
        return $this->view($user, $review);
    }

    private function isActiveClient(User $user): bool
    {
        return $user->isClientAccount() && $user->isActive();
    }
}
