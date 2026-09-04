<?php

namespace App\Modules\Bookings\Policies;

use App\Models\User;
use App\Modules\Bookings\Models\Booking;
use App\Shared\Policies\BasePolicy;

class BookingPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage bookings') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view bookings');
    }

    public function view(User $user, Booking $booking): bool
    {
        return $this->allows($user, 'view bookings');
    }

    public function cancel(User $user, Booking $booking): bool
    {
        return $this->allows($user, 'cancel bookings');
    }

    public function manageDispute(User $user, Booking $booking): bool
    {
        return $this->allows($user, 'manage booking disputes');
    }

    public function viewDisputes(User $user): bool
    {
        return $this->allows($user, 'view bookings');
    }
}
