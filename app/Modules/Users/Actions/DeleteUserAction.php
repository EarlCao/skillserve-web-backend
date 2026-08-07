<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: soft-delete a platform user account.
 *
 * Guards: the administrator-scope guard lives in UserManagementService
 * (administrator accounts are managed by the Administrator Management
 * module), and an administrator can never delete their own account.
 */
final class DeleteUserAction extends BaseAction
{
    /**
     * @throws ApiException when one of the deletion guards is violated.
     */
    public function handle(User $user, User $actor): User
    {
        if ($user->is($actor)) {
            throw new ApiException(
                'You cannot delete your own account.',
                422,
                errors: ['id' => ['You cannot delete your own account.']],
            );
        }

        if ($user->trashed()) {
            throw new ApiException(
                'The account is already deleted.',
                422,
                errors: ['id' => ['The account is already deleted.']],
            );
        }

        // Invalidate existing sessions, record who deleted the account, then
        // soft-delete it.
        $user->tokens()->delete();
        $user->update(['deleted_by' => $actor->id]);
        $user->delete();

        return $user;
    }
}
