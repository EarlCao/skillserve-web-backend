<?php

namespace App\Modules\ClientAuthentication\Listeners;

use App\Models\User;
use App\Modules\ClientAuthentication\Events\ClientAccountRestricted;
use App\Shared\Exceptions\AccountRestrictedException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Signs a mobile user out of their open app the moment an administrator
 * suspends, bans or deletes the account. Listens on the User model itself so
 * every path is covered — User Management, Provider Management and report
 * moderation — and pushes only once the change is committed.
 */
class PushAccountRestriction
{
    public function handle(User $user): void
    {
        $removed = ! $user->exists || $user->trashed();
        $restricted = $user->wasChanged('status') && ($user->isSuspended() || $user->isBanned());

        if ((! $removed && ! $restricted) || ! $user->isMobileAccount()) {
            return;
        }

        $event = $removed
            ? new ClientAccountRestricted(
                $user->id,
                ['status' => 'deleted', 'reason' => null, 'since' => now()->toIso8601String(), 'until' => null],
                'Your account was deleted by an administrator.',
            )
            : new ClientAccountRestricted(
                $user->id,
                AccountRestrictedException::describe($user),
                AccountRestrictedException::for($user)->getMessage(),
            );

        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $exception) {
                Log::warning('An account restriction could not be pushed live.', ['user_id' => $event->userId, 'error' => $exception->getMessage()]);
            }
        });
    }
}
