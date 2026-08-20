<?php

namespace App\Modules\Providers\Policies;

use App\Models\User;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for provider management actions.
 *
 * The Provider model policy slot is not claimed by another module,
 * so this module uses the standard policy pattern.
 * Super administrators bypass every check via Gate::before.
 */
class ProviderPolicy extends BasePolicy
{
    private function allows(User $user, string $permission): bool
    {
        return $user->hasPermissionTo('manage providers') || $user->hasPermissionTo($permission);
    }

    /**
     * Whether the user may manage provider accounts.
     */
    public function manage(User $user): bool
    {
        return $user->hasPermissionTo('manage providers');
    }

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view providers');
    }

    public function view(User $user): bool
    {
        return $this->allows($user, 'view providers');
    }

    public function update(User $user): bool
    {
        return $this->allows($user, 'edit providers');
    }

    public function delete(User $user): bool
    {
        return $this->allows($user, 'delete providers');
    }

    public function suspend(User $user): bool
    {
        return $this->allows($user, 'suspend providers');
    }

    public function activate(User $user): bool
    {
        return $this->allows($user, 'activate providers');
    }

    public function verify(User $user): bool
    {
        return $this->allows($user, 'verify providers');
    }

    public function reject(User $user): bool
    {
        return $this->allows($user, 'reject providers');
    }

    public function requestAdditionalInfo(User $user): bool
    {
        return $this->allows($user, 'verify providers');
    }

    public function removeVerification(User $user): bool
    {
        return $this->allows($user, 'verify providers');
    }
}
