<?php

namespace App\Modules\IdentityVerification\Notifications;

use App\Shared\Notifications\BaseNotification;

/**
 * Tells the account holder an administrator decided on their National ID.
 * Account-level, so it cannot be muted; it is also what makes the app
 * refresh the account's verification status the moment the decision lands.
 */
class IdentityDecisionNotification extends BaseNotification
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
            'type' => 'identity_verification',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'reason' => $this->reason,
        ];
    }
}
