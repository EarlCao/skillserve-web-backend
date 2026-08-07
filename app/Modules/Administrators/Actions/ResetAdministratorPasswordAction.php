<?php

namespace App\Modules\Administrators\Actions;

use App\Models\User;
use App\Modules\Administrators\Support\SystemRole;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: set a new password for an administrator account.
 *
 * All existing tokens are revoked so every current session must re-authenticate
 * with the new password — except, when an administrator resets their own
 * password, the session making the request (so the caller isn't logged out
 * mid-change).
 */
final class ResetAdministratorPasswordAction extends BaseAction
{
    public function handle(User $administrator, string $password, User $actor, ?int $keepTokenId = null): User
    {
        // This route lives in Administrator Management: only role-bearing
        // accounts (administrators) are in scope. Platform users are managed
        // through User Management.
        if (! $administrator->roles()->exists()) {
            throw new ApiException(
                'Administrator accounts only — platform users are managed in User Management.',
                422,
                errors: ['id' => ['The account is not an administrator.']],
            );
        }

        // A super administrator's password is exclusively self-managed — not
        // even another super administrator may change it. This lives in the
        // action (not only the policy) because super-admins bypass policies
        // via Gate::before, so the domain rule must hold for every caller.
        if ($administrator->hasRole(SystemRole::SUPER_ADMIN) && ! $actor->is($administrator)) {
            throw new ApiException(
                'Only the super administrator can change their own password.',
                403,
                errors: ['password' => ['Only the super administrator can change their own password.']],
            );
        }

        $administrator->update(['password' => $password]);

        // Invalidates every existing session except the one making the
        // request (when that session belongs to this account).
        $administrator->tokens()->when($keepTokenId, fn ($query) => $query->where('id', '!=', $keepTokenId))->delete();

        return $administrator;
    }
}
