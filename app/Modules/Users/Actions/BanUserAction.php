<?php

namespace App\Modules\Users\Actions;

use App\Models\User;
use App\Shared\Actions\BaseAction;
use App\Shared\Exceptions\ApiException;

/**
 * Single unit of work: ban a platform user account — either for a fixed
 * number of days (banned_until set, lifted automatically when it passes) or
 * permanently (banned_until null).
 *
 * Banned accounts cannot sign in (LoginAction rejects every status other than
 * "active" unless the temporary ban has expired).
 */
final class BanUserAction extends BaseAction
{
    /**
     * @throws ApiException when the account is already banned.
     */
    public function handle(
        User $user,
        User $actor,
        string $reason,
        string $duration = 'forever',
        ?int $days = null,
    ): User {
        if ($user->isBanned()) {
            throw new ApiException(
                'The account is already banned.',
                422,
                errors: ['status' => ['The account is already banned.']],
            );
        }

        $bannedUntil = $duration === 'days' ? now()->addDays((int) $days) : null;

        $user->update([
            'status' => 'banned',
            'banned_at' => now(),
            'banned_by' => $actor->id,
            'ban_reason' => $reason,
            'banned_until' => $bannedUntil,
            'unban_reason' => null,
            // A ban supersedes any lingering suspension state so the account
            // never shows as both suspended and banned.
            'suspended_at' => null,
            'suspended_by' => null,
            'suspension_reason' => null,
        ]);

        // Existing sessions are invalidated too — not just future logins.
        $user->tokens()->delete();

        return $user;
    }
}
