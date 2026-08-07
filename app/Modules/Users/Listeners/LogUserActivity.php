<?php

namespace App\Modules\Users\Listeners;

use App\Models\User;
use App\Modules\Users\Events\UserActivated;
use App\Modules\Users\Events\UserBanned;
use App\Modules\Users\Events\UserDeleted;
use App\Modules\Users\Events\UserSuspended;
use App\Modules\Users\Events\UserUnbanned;
use App\Modules\Users\Events\UserUpdated;

/**
 * Persists User Management events into the Spatie activity log.
 *
 * Registered explicitly in AppServiceProvider (module listeners live outside
 * app/Listeners, so auto-discovery does not apply).
 */
class LogUserActivity
{
    /**
     * Handle the user-module events.
     */
    public function handle(
        UserUpdated|UserSuspended|UserActivated|UserBanned|UserUnbanned|UserDeleted $event,
    ): void {
        match (true) {
            $event instanceof UserUpdated => $this->log(
                $event->actor, $event->user,
                ['before' => $event->before, 'after' => $event->after], 'user_updated',
            ),
            $event instanceof UserSuspended => $this->log(
                $event->actor, $event->user,
                ['reason' => $event->reason], 'user_suspended',
            ),
            $event instanceof UserActivated => $this->log(
                $event->actor, $event->user,
                [], 'user_activated',
            ),
            $event instanceof UserBanned => $this->log(
                $event->actor, $event->user,
                ['reason' => $event->reason], 'user_banned',
            ),
            $event instanceof UserUnbanned => $this->log(
                $event->actor, $event->user,
                ['reason' => $event->reason], 'user_unbanned',
            ),
            default => $this->log(
                $event->actor, $event->user,
                [], 'user_deleted',
            ),
        };
    }

    /**
     * Write a single activity-log entry.
     *
     * @param  array<string, mixed>  $properties
     */
    private function log(User $actor, User $subject, array $properties, string $description): void
    {
        activity('users')
            ->causedBy($actor)
            ->performedOn($subject)
            ->withProperties($properties)
            ->log($description);
    }
}
