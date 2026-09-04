<?php

namespace App\Modules\ReportsAndModeration\Policies;

use App\Models\User;
use App\Modules\ReportsAndModeration\Models\Report;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for report lifecycle actions.
 *
 * Super administrators bypass every check via Gate::before; every other role
 * needs "manage reports" or the granular permission for the action. Moderation
 * actions additionally re-check the target module's own gate/policy inside
 * ReportService, so reports can never be used to bypass User/Service/Review
 * authorization.
 */
class ReportPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage reports') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view reports');
    }

    public function view(User $user, Report $report): bool
    {
        return $this->allows($user, 'view reports');
    }

    public function investigate(User $user, Report $report): bool
    {
        return $this->allows($user, 'investigate reports');
    }

    public function addNote(User $user, Report $report): bool
    {
        return $this->allows($user, 'investigate reports');
    }

    public function resolve(User $user, Report $report): bool
    {
        return $this->allows($user, 'resolve reports');
    }

    public function reject(User $user, Report $report): bool
    {
        return $this->allows($user, 'resolve reports');
    }

    public function action(User $user, Report $report): bool
    {
        return $this->allows($user, 'manage moderation');
    }
}
