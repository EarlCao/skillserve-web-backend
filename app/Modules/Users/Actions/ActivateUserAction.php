<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: restore a suspended platform user account.
 *
 * Restoring clears the suspension trail and records who reactivated the
 * account. Banned accounts are terminal and cannot be restored here.
 */
final class ActivateUserAction extends BaseAction
{
    /**
     * @throws ApiException when the account is banned (terminal state).
     */
    public function handle(User $user, User $actor): User
    {
        if ($user->isBanned()) {
            throw new ApiException(
                'A banned account cannot be activated.',
                422,
                errors: ['status' => ['A banned account cannot be activated.']],
            );
        }

        if ($user->isActive()) {
            return $user;
        }

        $user->update([
            'status' => 'active',
            'activated_at' => now(),
            'activated_by' => $actor->id,
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
            // Drop any stale ban-lift markers so a later suspension recovery
            // isn't mislabelled as an unban in the moderation history.
            'banned_until' => null,
            'unban_reason' => null,
        ]);

        return $user;
    }
}
