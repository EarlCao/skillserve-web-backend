<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: temporarily suspend a platform user account.
 *
 * The account cannot sign in while suspended (LoginAction rejects every
 * status other than "active").
 */
final class SuspendUserAction extends BaseAction
{
    /**
     * @throws ApiException when the current account state cannot be suspended.
     */
    public function handle(User $user, User $actor, string $reason): User
    {
        if ($user->isSuspended()) {
            throw new ApiException(
                'The account is already suspended.',
                422,
                errors: ['status' => ['The account is already suspended.']],
            );
        }

        if ($user->isBanned()) {
            throw new ApiException(
                'A banned account cannot be suspended. Consider deleting it instead.',
                422,
                errors: ['status' => ['A banned account cannot be suspended.']],
            );
        }

        $user->update([
            'status' => 'suspended',
            'suspended_at' => now(),
            'suspended_by' => $actor->id,
            'suspension_reason' => $reason,
        ]);

        // Existing sessions are invalidated too — not just future logins.
        $user->tokens()->delete();

        return $user;
    }
}
