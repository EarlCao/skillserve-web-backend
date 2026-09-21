<?php

namespace App\Modules\Users\Listeners;

use App\Models\User;
use App\Modules\Users\Events\UserActivated;
use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Events\UserWarned;
use App\Modules\Users\Notifications\AccountStatusNotification;

/**
 * Mobile users hear about warnings, suspensions and reactivations of their
 * account. (Bans and unbans are emailed by SendUserModerationMail, because a
 * banned user cannot open the app.) Administrator accounts are not notified.
 */
class NotifyUserOfAccountAction
{
    public function handle(UserWarned|UserSuspended|UserActivated $event): void
    {
        // Re-read the full account: realtime delivery needs columns the
        // caller's copy may not have loaded.
        $user = User::query()->find($event->user->id);

        if (! $user || ! $user->isMobileAccount()) {
            return;
        }

        $user->notify(match (true) {
            $event instanceof UserWarned => new AccountStatusNotification(
                'warned', 'Warning from SkillServe',
                "An administrator issued a warning on your account: {$event->reason} Repeated violations can lead to suspension.",
                $event->reason,
            ),
            $event instanceof UserSuspended => new AccountStatusNotification(
                'suspended', 'Account suspended',
                "Your account was suspended. Reason: {$event->reason}",
                $event->reason,
            ),
            default => new AccountStatusNotification(
                'activated', 'Account active again',
                'Your account was reactivated. Welcome back — you can use SkillServe again.',
            ),
        });
    }
}
