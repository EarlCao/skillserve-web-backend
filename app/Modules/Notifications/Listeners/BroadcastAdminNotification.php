<?php

namespace App\Modules\Notifications\Listeners;

use App\Models\User;
use App\Modules\Notifications\Events\AdminNotificationCreated;
use App\Modules\Notifications\Notifications\AdminAlertNotification;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastAdminNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database'
            || ! $event->notification instanceof AdminAlertNotification
            || ! $event->notifiable instanceof User) {
            return;
        }

        try {
            event(new AdminNotificationCreated(
                $event->notifiable->id,
                $event->notification->toArray($event->notifiable),
            ));
        } catch (Throwable $exception) {
            // Stored already; the admin web picks it up on its next check.
            Log::warning('An admin alert could not be pushed live.', ['user_id' => $event->notifiable->id, 'error' => $exception->getMessage()]);
        }
    }
}
