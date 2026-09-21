<?php

namespace App\Modules\Users\Notifications;

use App\Shared\Notifications\BaseNotification;

/**
 * Tells a user an administrator acted on their account: a warning, a
 * suspension or a reactivation (M 1.6, M 15.4, M 16.4).
 *
 * No Settings category: account notices cannot be switched off.
 */
class AccountStatusNotification extends BaseNotification
{
    public function __construct(
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $reason = null,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'account_status',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'reason' => $this->reason,
        ];
    }
}
