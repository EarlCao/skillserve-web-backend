<?php

namespace App\Modules\Services\Policies;

use App\Models\User;
use App\Modules\Services\Models\Service;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for service-management actions.
 *
 * Super administrators bypass every check via Gate::before in
 * AppServiceProvider; every other role needs the "manage services"
 * permission.
 */
class ServicePolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage services') || $user->hasPermissionTo($permission);
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view services');
    }

    public function view(User $user, Service $service): bool
    {
        return $this->allows($user, 'view services');
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create services');
    }

    public function update(User $user, Service $service): bool
    {
        return $this->allows($user, 'edit services');
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->allows($user, 'delete services');
    }

    public function approve(User $user, Service $service): bool
    {
        return $this->allows($user, 'approve services');
    }

    public function reject(User $user, Service $service): bool
    {
        return $this->allows($user, 'reject services');
    }

    public function feature(User $user, Service $service): bool
    {
        return $this->allows($user, 'feature services');
    }

    public function hide(User $user, Service $service): bool
    {
        return $this->allows($user, 'edit services');
    }
}
