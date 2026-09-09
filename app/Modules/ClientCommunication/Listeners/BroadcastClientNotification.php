<?php

namespace App\Modules\ClientCommunication\Listeners;

use App\Models\User;
use App\Modules\ClientCommunication\Events\ClientNotificationCreated;
use Illuminate\Notifications\Events\NotificationSent;

class BroadcastClientNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel !== 'database' || ! $event->notifiable instanceof User || ! $event->notifiable->isClientAccount()) {
            return;
        }

        event(new ClientNotificationCreated(
            $event->notifiable->id,
            [
                'type' => $event->notification::class,
                'data' => $event->notification->toArray($event->notifiable),
            ],
        ));
    }
}
