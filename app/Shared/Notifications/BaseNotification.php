<?php

namespace App\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base class for notifications.
 *
 * By default notifications are sent through the "database" and "mail"
 * channels. Override via()/toMail()/toArray() in concrete notifications.
 */
abstract class BaseNotification extends Notification
{
    use Queueable;

    /**
     * Channels the notification is sent on.
     *
     * Defaults to the database channel only. Subclasses that want mail must
     * implement toMail() (or override via() and add the 'mail' channel).
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Database representation of the notification.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;
}
