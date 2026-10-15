<?php

namespace App\Modules\ReportsAndModeration\Notifications;

use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Notifications\BaseNotification;

/**
 * Tells the sender that an administrator removed one of their chat messages
 * after a report, and why. Opens the conversation it was in. Not mutable: it
 * is a moderation notice, not a chat message.
 */
class MessageRemovedNotification extends BaseNotification
{
    public function __construct(
        private readonly Message $message,
        private readonly ?string $reason,
    ) {}

    public function toArray(object $notifiable): array
    {
        $text = 'An administrator removed one of your chat messages because it breaks the community guidelines.'
            .($this->reason ? " Reason: {$this->reason}" : '');

        return [
            'type' => 'message_moderation',
            'action' => 'removed',
            'title' => 'Message removed',
            'message' => $text,
            'body' => $text,
            'reason' => $this->reason,
            'booking_id' => $this->message->booking_id,
            'inquiry_id' => $this->message->inquiry_id,
        ];
    }
}
