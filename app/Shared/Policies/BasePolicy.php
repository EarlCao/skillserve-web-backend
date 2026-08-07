<?php

namespace App\Shared\Policies;

use App\Models\User;

/**
 * Base class for model policies.
 *
 * Concrete policies should extend this class and implement the standard
 * Gate methods (viewAny, view, create, update, delete, restore, forceDelete).
 */
abstract class BasePolicy
{
    /**
     * Runs before any policy method.
     *
     * Return true to grant every ability, false to deny every ability, or
     * null to fall through to the specific policy method. Extend this in
     * concrete policies, e.g. to allow a "super-admin" role to bypass checks.
     */
    public function before(User $user, string $ability): ?bool
    {
        // if ($user->hasRole('super-admin')) {
        //     return true;
        // }

        return null;
    }
}
