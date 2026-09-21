<?php

namespace App\Shared\Exceptions;

use App\Models\User;
use App\Shared\Helpers\BusinessTime;

/**
 * Refuses a suspended or banned account, and tells the app why: the envelope's
 * `meta.account` carries {status, reason, since, until} so the app can show a
 * restriction screen instead of a bare error (M 1.6, M 15.4).
 */
final class AccountRestrictedException extends ApiException
{
    public static function for(User $user): self
    {
        $restriction = self::describe($user);

        $message = match ($restriction['status']) {
            'suspended' => 'Your account is suspended.',
            'banned' => $restriction['until']
                ? 'Your account is banned until '.BusinessTime::format($user->banned_until).'.'
                : 'Your account has been permanently banned.',
            default => 'Your account is not active.',
        };

        return new self($message, 403, meta: ['account' => $restriction]);
    }

    /**
     * @return array{status: string, reason: ?string, since: ?string, until: ?string}
     */
    public static function describe(User $user): array
    {
        return match ($user->status) {
            'suspended' => [
                'status' => 'suspended',
                'reason' => $user->suspension_reason,
                'since' => $user->suspended_at?->toIso8601String(),
                'until' => null,
            ],
            'banned' => [
                'status' => 'banned',
                'reason' => $user->ban_reason,
                'since' => $user->banned_at?->toIso8601String(),
                'until' => $user->banned_until?->toIso8601String(),
            ],
            default => [
                'status' => (string) $user->status,
                'reason' => null,
                'since' => null,
                'until' => null,
            ],
        };
    }
}
