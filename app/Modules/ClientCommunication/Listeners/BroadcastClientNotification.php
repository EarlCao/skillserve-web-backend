<?php

namespace App\Modules\ClientCommunication\Listeners;

use App\Models\User;
use App\Modules\ClientCommunication\Events\ClientNotificationCreated;
use App\Modules\Settings\Services\SettingsService;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastClientNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $event->notifiable->isMobileAccount()) {
            return;
        }

        // System Settings → Notifications → "Push notifications": off keeps
        // notifications in the in-app feed but stops pushing them to phones.
        if (! app(SettingsService::class)->value('notifications', 'push_notifications_enabled')) {
            return;
        }

        try {
            event(new ClientNotificationCreated(
                $event->notifiable->id,
                [
                    'type' => $event->notification::class,
                    'data' => $event->notification->toArray($event->notifiable),
                ],
            ));
        } catch (Throwable $exception) {
            // Stored already; the app picks it up on its next check.
            Log::warning('A notification could not be pushed live.', ['user_id' => $event->notifiable->id, 'error' => $exception->getMessage()]);
        }
    }
}
