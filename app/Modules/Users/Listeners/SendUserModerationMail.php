<?php

namespace App\Modules\Users\Listeners;

use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserUnbanned;
use App\Modules\Users\Mail\UserBannedMail;
use App\Modules\Users\Mail\UserUnbannedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the affected user on ban and unban events.
 *
 * Registered explicitly in AppServiceProvider. Delivery is best-effort: a
 * misconfigured mail transport must never break a moderation action, so any
 * failure is swallowed here (the moderation itself is already recorded in
 * the activity log).
 */
class SendUserModerationMail
{
    public function handle(UserBanned|UserUnbanned $event): void
    {
        try {
            if ($event instanceof UserBanned) {
                Mail::to($event->user)->send(
                    new UserBannedMail($event->user, $event->reason, $event->user->banned_until),
                );

                return;
            }

            Mail::to($event->user)->send(new UserUnbannedMail($event->user, $event->reason));
        } catch (\Throwable) {
            // Best-effort only.
        }
    }
}
