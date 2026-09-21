<?php

namespace App\Modules\ClientCommunication\Policies;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;

/**
 * Any active mobile account — customer or provider — may report the other
 * party on one of their own bookings, and read only the reports they filed.
 */
class ClientReportPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isActiveMobileAccount($user);
    }

    public function create(User $user): bool
    {
        return $this->isActiveMobileAccount($user);
    }

    public function view(User $user, Report $report): bool
    {
        return $this->isActiveMobileAccount($user)
            && (int) $report->reporter_id === (int) $user->id;
    }

    private function isActiveMobileAccount(User $user): bool
    {
        return $user->isMobileAccount() && $user->isActive();
    }
}
