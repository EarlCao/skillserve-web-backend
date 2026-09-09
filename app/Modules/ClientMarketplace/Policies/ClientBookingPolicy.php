<?php

namespace App\Modules\ClientMarketplace\Policies;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;

class ClientBookingPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveClient($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveClient($user);
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->isActiveClient($user) && $booking->client_id === $user->id;
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->view($user, $booking);
    }

    private function isActiveClient(User $user): bool
    {
        return $user->isClientAccount() && $user->isActive();
    }
}
