<?php

namespace App\Modules\Providers\Notifications;

use App\Shared\Notifications\BaseNotification;

/**
 * Tells a provider an administrator decided on their verification or their
 * provider account (M 9.4, M 9.6). Account-level: cannot be muted.
 */
class ProviderAccountNotification extends BaseNotification
{
    public function __construct(
        private readonly string $type,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $reason = null,
    ) {}

    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'reason' => $this->reason,
        ];
    }
}
