<?php

namespace App\Modules\ClientCommunication\Notifications;

use App\Modules\ReportsAndModeration\Models\Message;
use App\Shared\Notifications\BaseNotification;
use Illuminate\Support\Str;

/**
 * Tells someone the other party on a booking wrote to them.
 */
class BookingMessageNotification extends BaseNotification
{
    public function __construct(
        private readonly Message $message,
        private readonly string $senderName,
    ) {}

    /** Muted by the "Messages" switch in the app's settings. */
    public function notificationCategory(): ?string
    {
        return 'message';
    }

    public function toArray(object $notifiable): array
    {
        // A preview only: the feed is not the place to reproduce a whole
        // conversation, and the thread is one tap away.
        $preview = Str::limit($this->message->content, 120);

        return [
            'type' => 'booking_message',
            'title' => "New message from {$this->senderName}",
            'message' => $preview,
            'body' => $preview,
            'booking_id' => $this->message->booking_id,
            'message_id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'sender_name' => $this->senderName,
        ];
    }
}
