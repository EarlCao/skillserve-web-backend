<?php

namespace App\Modules\Support\Policies;

use App\Models\User;
use App\Modules\Support\Models\SupportTicket;
use App\Shared\Policies\BasePolicy;

class SupportTicketPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage support') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view support');
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        return $this->allows($user, 'view support');
    }

    public function assign(User $user, SupportTicket $ticket): bool
    {
        return $this->allows($user, 'assign support tickets');
    }

    public function respond(User $user, SupportTicket $ticket): bool
    {
        return $this->allows($user, 'respond to support tickets');
    }

    public function resolve(User $user, SupportTicket $ticket): bool
    {
        return $this->allows($user, 'resolve support tickets');
    }
}
