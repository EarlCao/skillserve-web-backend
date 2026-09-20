<?php

namespace App\Modules\Services\Notifications;

use App\Modules\Services\Models\Service;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells a provider that an administrator acted on one of their services.
 */
class ServiceModerationNotification extends BaseNotification
{
    public function __construct(
        private readonly Service $service,
        private readonly string $action,
        private readonly string $title,
        private readonly string $message,
        private readonly ?string $reason = null,
    ) {}

    /** Muted by the "Service updates" switch in the app's settings. */
    public function notificationCategory(): ?string
    {
        return 'service';
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'service_moderation',
            'action' => $this->action,
            'title' => $this->title,
            'message' => $this->message,
            'body' => $this->message,
            'service_id' => $this->service->id,
            'service_title' => $this->service->title,
            'reason' => $this->reason,
        ];
    }
}
