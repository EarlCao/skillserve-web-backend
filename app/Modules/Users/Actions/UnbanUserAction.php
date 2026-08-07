<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: lift a ban from a platform user account.
 *
 * The account returns to "active" and can sign in again. The original ban
 * record (banned_at / banned_by / ban_reason) is kept for history; the lift
 * is recorded through the reactivation fields plus an optional note.
 */
final class UnbanUserAction extends BaseAction
{
    /**
     * @throws ApiException when the account is not currently banned.
     */
    public function handle(User $user, User $actor, ?string $reason = null): User
    {
        if (! $user->isBanned()) {
            throw new ApiException(
                'The account is not currently banned.',
                422,
                errors: ['status' => ['The account is not currently banned.']],
            );
        }

        $user->update([
            'status' => 'active',
            'banned_until' => null,
            // Always record a note (a default when none was provided) so an
            // unban is distinguishable from a plain activation in history.
            'unban_reason' => $reason ?? 'Ban lifted by administrator.',
            'activated_at' => now(),
            'activated_by' => $actor->id,
        ]);

        return $user;
    }
}
