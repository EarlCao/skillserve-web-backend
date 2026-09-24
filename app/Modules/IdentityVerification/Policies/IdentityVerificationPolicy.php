<?php

namespace App\Modules\IdentityVerification\Policies;

use App\Models\User;
use App\Modules\IdentityVerification\Models\IdentityVerification;
use App\Shared\Policies\BasePolicy;

/**
 * Authorization for the National ID review queue.
 *
 * Reading a submission means looking at someone's government ID, so
 * "view identity verifications" is a permission in its own right rather than
 * something a general admin role carries by default. Approving and rejecting
 * are separated again, so a reviewer can be given one without the other.
 *
 * Super administrators bypass every check via Gate::before.
 */
class IdentityVerificationPolicy extends BasePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('view identity verifications');
    }

    public function view(User $user, IdentityVerification $verification): bool
    {
        return $user->hasPermissionTo('view identity verifications');
    }

    public function verify(User $user, IdentityVerification $verification): bool
    {
        return $user->hasPermissionTo('verify identities');
    }

    public function reject(User $user, IdentityVerification $verification): bool
    {
        return $user->hasPermissionTo('reject identities');
    }
}
