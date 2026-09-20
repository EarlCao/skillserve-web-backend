<?php

namespace App\Shared\Notifications;

use App\Modules\ClientPreferences\Services\ClientPreferenceService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Base class for notifications.
 *
 * By default notifications are sent through the "database" channel.
 * Override via()/toMail()/toArray() in concrete notifications.
 *
 * Subclasses that belong to a category a mobile user can switch off in
 * Settings declare it through {@see notificationCategory()}; delivery is
 * then suppressed for accounts that muted it. Notifications with no
 * category — account security, verification, password resets — are never
 * gated, which is why those classes extend Laravel's Notification directly.
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
     * An empty array means "do not deliver", which is how a muted category
     * is honoured. Subclasses overriding via() should call
     * {@see isMutedFor()} (or defer to channelsFor()) to keep that working.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channelsFor($notifiable, ['database']);
    }

    /**
     * The Settings category this notification belongs to — one of the keys
     * of ClientPreference::NOTIFICATION_CATEGORIES — or null when it must
     * always be delivered.
     */
    public function notificationCategory(): ?string
    {
        return null;
    }

    /**
     * The given channels, or none when the recipient muted this category.
     *
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    protected function channelsFor(object $notifiable, array $channels): array
    {
        return $this->isMutedFor($notifiable) ? [] : $channels;
    }

    /** Whether this recipient switched this notification's category off. */
    protected function isMutedFor(object $notifiable): bool
    {
        return ! app(ClientPreferenceService::class)
            ->allowsNotification($notifiable, $this->notificationCategory());
    }

    /**
     * Database representation of the notification.
     *
     * @return array<string, mixed>
     */
    abstract public function toArray(object $notifiable): array;
}
