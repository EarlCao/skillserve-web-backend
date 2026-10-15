<?php

namespace App\Modules\Users\Listeners;

use App\Models\User;
use App\Modules\Providers\Events\ProviderDeleted;
use App\Modules\Settings\Services\SettingsService;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserUnbanned;
use App\Modules\Users\Mail\UserBannedMail;
use App\Modules\Users\Mail\UserDeletedMail;
use App\Modules\Users\Mail\UserUnbannedMail;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the affected user on ban, unban and deletion — a deleted (or
 * banned) person cannot sign in to read a notification.
 *
 * Registered explicitly in AppServiceProvider. Delivery is best-effort: a
 * misconfigured mail transport must never break a moderation action, so any
 * failure is swallowed here (the moderation itself is already recorded in
 * the activity log).
 */
class SendUserModerationMail
{
    public function handle(UserBanned|UserUnbanned|UserDeleted|ProviderDeleted $event): void
    {
        // System Settings → Notifications → "Email notifications". Account
        // security mail (OTP, password reset) is never switched off.
        if (! app(SettingsService::class)->value('notifications', 'email_notifications_enabled')) {
            return;
        }

        try {
            if ($event instanceof UserDeleted || $event instanceof ProviderDeleted) {
                // The account is already soft-deleted, so read it with trashed.
                $user = User::withTrashed()->find($event instanceof UserDeleted ? $event->user->id : $event->providerProfile->user_id);
                if ($user) {
                    Mail::to($user)->send(new UserDeletedMail($user, $event->reason));
                }

                return;
            }

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
