<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: permanently ban a platform user account.
 *
 * Banning is terminal — the account can never be activated again and cannot
 * sign in (LoginAction rejects every status other than "active").
 */
final class BanUserAction extends BaseAction
{
    /**
     * @throws ApiException when the account is already banned.
     */
    public function handle(User $user, User $actor, string $reason): User
    {
        if ($user->isBanned()) {
            throw new ApiException(
                'The account is already banned.',
                422,
                errors: ['status' => ['The account is already banned.']],
            );
        }

        $user->update([
            'status' => 'banned',
            'banned_at' => now(),
            'banned_by' => $actor->id,
            'ban_reason' => $reason,
            // A ban is terminal — drop any lingering suspension state so the
            // account never shows as both suspended and banned.
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ]);

        // Existing sessions are invalidated too — not just future logins.
        $user->tokens()->delete();

        return $user;
    }
}
